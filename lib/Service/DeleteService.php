<?php

declare(strict_types=1);

namespace OCA\CromCull\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\Share\IManager as IShareManager;

class DeleteService {
	private IDBConnection $db;
	private IRootFolder $rootFolder;
	private IShareManager $shareManager;
	private IConfig $config;

	public function __construct(
		IDBConnection $db,
		IRootFolder $rootFolder,
		IShareManager $shareManager,
		IConfig $config
	) {
		$this->db = $db;
		$this->rootFolder = $rootFolder;
		$this->shareManager = $shareManager;
		$this->config = $config;
	}

	public function deleteGroupFiles(
		string $userId,
		int $groupId,
		array $selectedFileIds,
		array $displayedPaths
	): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);

		$row = $this->fetchGroup($groupId, $userId);
		if ($row === null) {
			return ['status' => 'error', 'message' => 'Group not found or not pending'];
		}

		$allFileIds = json_decode($row['file_ids'], true);
		$selectedSet = array_flip(array_map('intval', $selectedFileIds));
		$expectedHash = $row['hash'];

		$selectedMembers = [];
		foreach ($selectedFileIds as $fileId) {
			$fileId = (int)$fileId;
			try {
				$nodes = $userFolder->getById($fileId);
				if (empty($nodes)) {
					$this->markChanged($groupId);
					return [
						'status' => 'changed',
						'message' => 'A selected file no longer exists — please rescan',
					];
				}
				$node = $nodes[0];
				if (!($node instanceof File)) {
					$this->markChanged($groupId);
					return [
						'status' => 'changed',
						'message' => 'A selected file is no longer a regular file — please rescan',
					];
				}
				$relativePath = $userFolder->getRelativePath($node->getPath());

				if (isset($displayedPaths[$fileId]) && $displayedPaths[$fileId] !== $relativePath) {
					$this->markChanged($groupId);
					return [
						'status' => 'changed',
						'message' => 'A selected file has been moved or renamed — please rescan',
					];
				}

				$currentHash = $this->hashFile($node);
				if ($currentHash !== $expectedHash) {
					$this->markChanged($groupId);
					return [
						'status' => 'changed',
						'message' => 'A selected file has been modified — please rescan',
					];
				}

				if ($this->isProtected($node, $userId, $userFolder)) {
					$this->markChanged($groupId);
					return [
						'status' => 'changed',
						'message' => 'A selected file is now in a group folder or shared — please rescan',
					];
				}

				$selectedMembers[$fileId] = [
					'node' => $node,
					'path' => $relativePath,
				];
			} catch (\Exception $e) {
				$this->markChanged($groupId);
				return [
					'status' => 'changed',
					'message' => 'A selected file could not be accessed — please rescan',
				];
			}
		}

		$keptId = 0;
		$keptPath = '';
		$keptVerified = false;
		foreach ($allFileIds as $fileId) {
			if (isset($selectedSet[(int)$fileId])) {
				continue;
			}
			try {
				$nodes = $userFolder->getById($fileId);
				if (empty($nodes) || !($nodes[0] instanceof File)) {
					continue;
				}
				$node = $nodes[0];
				$keptId = (int)$fileId;
				$keptPath = $userFolder->getRelativePath($node->getPath());
				if (!$keptVerified) {
					$currentHash = $this->hashFile($node);
					if ($currentHash === $expectedHash) {
						$keptVerified = true;
					}
				}
				break;
			} catch (\Exception $e) {
				continue;
			}
		}

		if (!$keptVerified) {
			$this->markChanged($groupId);
			return [
				'status' => 'changed',
				'message' => 'No verified duplicate remains — please rescan',
			];
		}

		$deletedFiles = [];
		$lastError = '';
		foreach ($selectedMembers as $fileId => $member) {
			try {
				$member['node']->delete();
				$deletedFiles[] = [
					'fileid' => $fileId,
					'path' => $member['path'],
				];
			} catch (\Exception $e) {
				$lastError = $e->getMessage();
				continue;
			}
		}

		if (empty($deletedFiles)) {
			return [
				'status' => 'error',
				'message' => 'Could not delete files: ' . ($lastError ?: 'unknown error'),
			];
		}

		$this->writeCsvLog($userId, $row, $keptId, $keptPath, $deletedFiles);
		$this->markResolved($groupId);

		return [
			'status' => 'resolved',
			'deleted' => count($deletedFiles),
		];
	}

	private function hashFile(File $node): string {
		$handle = $node->fopen('r');
		if (!$handle) {
			return '';
		}
		$ctx = hash_init('sha256');
		while (!feof($handle)) {
			hash_update($ctx, fread($handle, 8192));
		}
		fclose($handle);
		return hash_final($ctx);
	}

	private function isProtected($node, string $userId, $userFolder): bool {
		$storage = $node->getStorage();
		if ($storage->instanceOfStorage(\OCA\GroupFolders\Mount\GroupFolderStorage::class)) {
			return true;
		}
		if ($storage->instanceOfStorage(\OCA\Files_Sharing\SharedStorage::class)) {
			return true;
		}

		$current = $node;
		while ($current !== null) {
			if ($this->hasShares($current, $userId)) {
				return true;
			}
			if ($current->getId() === $userFolder->getId()) {
				break;
			}
			try {
				$current = $current->getParent();
			} catch (\Exception $e) {
				break;
			}
		}

		return false;
	}

	private function hasShares($node, string $userId): bool {
		$shareTypes = [
			\OCP\Share\IShare::TYPE_USER,
			\OCP\Share\IShare::TYPE_GROUP,
			\OCP\Share\IShare::TYPE_LINK,
			\OCP\Share\IShare::TYPE_EMAIL,
			\OCP\Share\IShare::TYPE_REMOTE,
			\OCP\Share\IShare::TYPE_ROOM,
		];

		foreach ($shareTypes as $type) {
			try {
				$shares = $this->shareManager->getSharesBy($userId, $type, $node, false, 1);
				if (!empty($shares)) {
					return true;
				}
			} catch (\Exception $e) {
				continue;
			}
		}

		return false;
	}

	private function fetchGroup(int $groupId, string $userId): ?array {
		$pattern = '%' . $this->db->escapeLikeParameter('_' . $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('cromcull_groups')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('scan_id', $qb->createNamedParameter($pattern)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row ?: null;
	}

	private function markChanged(int $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('cromcull_groups')
			->set('status', $qb->createNamedParameter('changed'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function markResolved(int $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('cromcull_groups')
			->set('status', $qb->createNamedParameter('resolved'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function getLogDir(): string {
		$dataDir = $this->config->getSystemValue('datadirectory', '/var/www/nextcloud/data');
		$instanceId = $this->config->getSystemValue('instanceid', '');
		return $dataDir . '/appdata_' . $instanceId . '/cromcull';
	}

	private function writeCsvLog(
		string $userId,
		array $groupRow,
		int $keptId,
		string $keptPath,
		array $deletedFiles
	): void {
		$logDir = $this->getLogDir();
		if (!is_dir($logDir)) {
			mkdir($logDir, 0750, true);
		}

		$year = date('Y');
		$logFile = $logDir . '/cromcull-delete-log-' . $year . '.csv';
		$isNew = !file_exists($logFile);

		$handle = fopen($logFile, 'a');
		if (!$handle) {
			return;
		}

		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			return;
		}

		if ($isNew && filesize($logFile) === 0) {
			fputcsv($handle, [
				'timestamp',
				'user',
				'kept_path',
				'kept_fileid',
				'deleted_path',
				'deleted_fileid',
				'size_bytes',
				'hash',
				'scan_id',
			]);
		}

		$timestamp = date('c');
		foreach ($deletedFiles as $file) {
			fputcsv($handle, [
				$timestamp,
				$userId,
				$keptPath,
				$keptId,
				$file['path'],
				$file['fileid'],
				$groupRow['size'],
				$groupRow['hash'],
				$groupRow['scan_id'],
			]);
		}

		flock($handle, LOCK_UN);
		fclose($handle);
	}
}
