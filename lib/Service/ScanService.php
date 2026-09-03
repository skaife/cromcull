<?php

declare(strict_types=1);

namespace OCA\CromCull\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

class ScanService {
	private const PARTIAL_HASH_BYTES = 65536;
	private const HASH_ALGO = 'sha256';

	private IDBConnection $db;
	private IRootFolder $rootFolder;
	private IConfig $config;
	private IgnoreBaselineService $baselineService;
	private IMountManager $mountManager;
	private IShareManager $shareManager;

	public function __construct(
		IDBConnection $db,
		IRootFolder $rootFolder,
		IConfig $config,
		IgnoreBaselineService $baselineService,
		IMountManager $mountManager,
		IShareManager $shareManager
	) {
		$this->db = $db;
		$this->rootFolder = $rootFolder;
		$this->config = $config;
		$this->baselineService = $baselineService;
		$this->mountManager = $mountManager;
		$this->shareManager = $shareManager;
	}

	public function scan(string $userId): array {
		$scanId = date('c') . '_' . $userId;

		$userFolder = $this->rootFolder->getUserFolder($userId);

		$minSize = (int)$this->config->getAppValue('cromcull', 'min_size', '0');
		$maxSize = (int)$this->config->getAppValue('cromcull', 'max_size', '0');
		$ignoredExtensions = $this->getIgnoredExtensions();
		$dirMimeId = $this->getDirectoryMimeTypeId();

		$storageMounts = $this->getUserStorageMounts($userId);

		$sizeCounts = [];
		foreach ($storageMounts as $mount) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('size')
				->selectAlias($qb->func()->count('*'), 'cnt')
				->from('filecache')
				->where($qb->expr()->eq('storage', $qb->createNamedParameter($mount['storage_id'], IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter($dirMimeId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gte('size', $qb->createNamedParameter($minSize, IQueryBuilder::PARAM_INT)))
				->groupBy('size');

			$escapedRoot = $this->db->escapeLikeParameter($mount['root_path']);
			$qb->andWhere($qb->expr()->like('path', $qb->createNamedParameter($escapedRoot . '/%')));

			if ($maxSize > 0) {
				$qb->andWhere($qb->expr()->lte('size', $qb->createNamedParameter($maxSize, IQueryBuilder::PARAM_INT)));
			}

			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$size = (int)$row['size'];
				$sizeCounts[$size] = ($sizeCounts[$size] ?? 0) + (int)$row['cnt'];
			}
			$result->closeCursor();
		}

		$candidateSizes = array_keys(array_filter($sizeCounts, fn($cnt) => $cnt >= 2));
		if (empty($candidateSizes)) {
			$this->writeResults($scanId, $userId, []);
			$drift = $this->baselineService->checkAndUpdateBaseline($userId);
			return ['scan_id' => $scanId, 'group_count' => 0, 'drift' => $drift];
		}

		$candidates = [];
		foreach ($storageMounts as $mount) {
			foreach (array_chunk($candidateSizes, 100) as $sizeChunk) {
				$qb = $this->db->getQueryBuilder();
				$qb->select('fileid', 'path', 'name', 'size')
					->from('filecache')
					->where($qb->expr()->eq('storage', $qb->createNamedParameter($mount['storage_id'], IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter($dirMimeId, IQueryBuilder::PARAM_INT)));

				$escapedRoot = $this->db->escapeLikeParameter($mount['root_path']);
				$qb->andWhere($qb->expr()->like('path', $qb->createNamedParameter($escapedRoot . '/%')));

				$sizeParams = [];
				foreach ($sizeChunk as $s) {
					$sizeParams[] = $qb->createNamedParameter($s, IQueryBuilder::PARAM_INT);
				}
				$qb->andWhere($qb->expr()->in('size', $sizeParams));

				$result = $qb->executeQuery();
				while ($row = $result->fetch()) {
					$size = (int)$row['size'];
					$candidates[$size][] = [
						'fileid' => (int)$row['fileid'],
						'path' => $row['path'],
						'name' => $row['name'],
						'storage_id' => $mount['storage_id'],
					];
				}
				$result->closeCursor();
			}
		}

		foreach ($candidates as $size => $files) {
			$seen = [];
			$unique = [];
			foreach ($files as $file) {
				if (!isset($seen[$file['fileid']])) {
					$seen[$file['fileid']] = true;
					$unique[] = $file;
				}
			}
			if (count($unique) >= 2) {
				$candidates[$size] = $unique;
			} else {
				unset($candidates[$size]);
			}
		}

		$ignoredFolders = $this->getIgnoredFolderPaths($userFolder, $userId);
		if (!empty($ignoredFolders)) {
			foreach ($candidates as $size => $files) {
				$candidates[$size] = array_values(array_filter($files, function ($f) use ($ignoredFolders) {
					$storageIgnored = $ignoredFolders[$f['storage_id']] ?? [];
					foreach ($storageIgnored as $folderPath) {
						if (str_starts_with($f['path'], $folderPath)) {
							return false;
						}
					}
					return true;
				}));
				if (count($candidates[$size]) < 2) {
					unset($candidates[$size]);
				}
			}
		}

		$groups = [];

		foreach ($candidates as $size => $files) {
			$files = array_values(array_filter($files, function ($f) use ($ignoredExtensions) {
				if ($f['name'] === '.cromcull_ignore') {
					return false;
				}
				if (!empty($ignoredExtensions)) {
					$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
					if (isset($ignoredExtensions[$ext])) {
						return false;
					}
				}
				return true;
			}));
			if (count($files) < 2) {
				continue;
			}

			$partialGroups = $this->computePartialHashes($userFolder, $files);

			foreach ($partialGroups as $partialFiles) {
				if (count($partialFiles) < 2) {
					continue;
				}

				$fullGroups = $this->computeFullHashes($userFolder, $partialFiles);

				foreach ($fullGroups as $fullHash => $matchedFiles) {
					if (count($matchedFiles) < 2) {
						continue;
					}
					$groups[] = [
						'hash' => $fullHash,
						'size' => $size,
						'file_ids' => array_map(function ($f) {
							return $f['fileid'];
						}, $matchedFiles),
					];
				}
			}
		}

		foreach ($groups as &$group) {
			$protectedIds = [];
			foreach ($group['file_ids'] as $fileId) {
				try {
					$nodes = $userFolder->getById($fileId);
					if (!empty($nodes) && $this->isFileProtected($nodes[0], $userId, $userFolder)) {
						$protectedIds[] = $fileId;
					}
				} catch (\Exception $e) {
					continue;
				}
			}
			$group['protected_ids'] = $protectedIds;
		}
		unset($group);

		$this->writeResults($scanId, $userId, $groups);

		$drift = $this->baselineService->checkAndUpdateBaseline($userId);

		return [
			'scan_id' => $scanId,
			'group_count' => count($groups),
			'drift' => $drift,
		];
	}

	private function getUserStorageMounts(string $userId): array {
		$userPath = '/' . $userId . '/files';
		$homeMount = $this->mountManager->find($userPath);
		$subMounts = $this->mountManager->findIn($userPath);

		$homeStorageId = $homeMount->getStorage()->getCache()->getNumericStorageId();
		$homeRootPath = $homeMount->getInternalPath(rtrim($userPath, '/'));

		$seen = [];
		$result = [];

		$homeKey = $homeStorageId . ':' . $homeRootPath;
		$seen[$homeKey] = true;
		$result[] = [
			'storage_id' => $homeStorageId,
			'root_path' => $homeRootPath,
		];

		foreach ($subMounts as $mount) {
			$storage = $mount->getStorage();
			if (!$storage) {
				continue;
			}

			$storageId = $storage->getCache()->getNumericStorageId();

			if ($storageId === $homeStorageId) {
				continue;
			}

			$cache = $storage->getCache();
			$rootEntry = $cache->get('');
			if ($rootEntry === false) {
				continue;
			}

			$rootFileId = $rootEntry->getId();
			$qb = $this->db->getQueryBuilder();
			$qb->select('path')
				->from('filecache')
				->where($qb->expr()->eq('fileid', $qb->createNamedParameter($rootFileId, IQueryBuilder::PARAM_INT)));
			$pathResult = $qb->executeQuery();
			$rootPath = $pathResult->fetchOne();
			$pathResult->closeCursor();

			if ($rootPath === false) {
				continue;
			}

			$key = $storageId . ':' . $rootPath;
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;

			$result[] = [
				'storage_id' => $storageId,
				'root_path' => $rootPath,
			];
		}

		return $result;
	}

	private function getDirectoryMimeTypeId(): int {
		$result = $this->db->executeQuery(
			'SELECT `id` FROM `*PREFIX*mimetypes` WHERE `mimetype` = ?',
			['httpd/unix-directory']
		);
		$id = (int)$result->fetchOne();
		$result->closeCursor();
		return $id;
	}

	private function getIgnoredExtensions(): array {
		$raw = $this->config->getAppValue('cromcull', 'ignored_extensions', '');
		if ($raw === '') {
			return [];
		}
		$exts = array_filter(array_map(function ($e) {
			return strtolower(ltrim(trim($e), '.'));
		}, explode(',', $raw)));
		return array_flip($exts);
	}

	private function getIgnoredFolderPaths(Folder $userFolder, string $userId): array {
		$nodes = $userFolder->search('.cromcull_ignore');
		$ignoredByStorage = [];

		foreach ($nodes as $node) {
			if (!($node instanceof File) || $node->getName() !== '.cromcull_ignore') {
				continue;
			}

			try {
				$config = CromcullIgnoreParser::parse($node->getContent());
			} catch (\Exception $e) {
				continue;
			}

			if (!$config['valid']) {
				continue;
			}

			if (!$config['admin_excluded'] && !in_array($userId, $config['users'])) {
				continue;
			}

			$parent = $node->getParent();
			$storageId = $parent->getStorage()->getCache()->getNumericStorageId();

			$qb = $this->db->getQueryBuilder();
			$qb->select('path')
				->from('filecache')
				->where($qb->expr()->eq('fileid', $qb->createNamedParameter($parent->getId(), IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			$actualPath = $result->fetchOne();
			$result->closeCursor();

			if ($actualPath !== false) {
				$ignoredByStorage[$storageId][] = $actualPath . '/';
			}
		}

		return $ignoredByStorage;
	}

	private function computePartialHashes($userFolder, array $files): array {
		$groups = [];
		foreach ($files as $file) {
			try {
				$nodes = $userFolder->getById($file['fileid']);
				if (empty($nodes) || !($nodes[0] instanceof File)) {
					continue;
				}

				$handle = $nodes[0]->fopen('r');
				if (!$handle) {
					continue;
				}

				$chunk = fread($handle, self::PARTIAL_HASH_BYTES);
				fclose($handle);

				$hash = hash(self::HASH_ALGO, $chunk);
				$groups[$hash][] = $file;
			} catch (\Exception $e) {
				continue;
			}
		}
		return $groups;
	}

	private function computeFullHashes($userFolder, array $files): array {
		$groups = [];
		foreach ($files as $file) {
			try {
				$nodes = $userFolder->getById($file['fileid']);
				if (empty($nodes) || !($nodes[0] instanceof File)) {
					continue;
				}

				$handle = $nodes[0]->fopen('r');
				if (!$handle) {
					continue;
				}

				$ctx = hash_init(self::HASH_ALGO);
				while (!feof($handle)) {
					hash_update($ctx, fread($handle, 8192));
				}
				fclose($handle);

				$hash = hash_final($ctx);
				$groups[$hash][] = $file;
			} catch (\Exception $e) {
				continue;
			}
		}
		return $groups;
	}

	private function isFileProtected($node, string $userId, $userFolder): bool {
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
			IShare::TYPE_USER,
			IShare::TYPE_GROUP,
			IShare::TYPE_LINK,
			IShare::TYPE_EMAIL,
			IShare::TYPE_REMOTE,
			IShare::TYPE_ROOM,
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

	private function writeResults(string $scanId, string $userId, array $groups): void {
		$this->db->beginTransaction();
		try {
			$pattern = '%' . $this->db->escapeLikeParameter('_' . $userId);
			$qb = $this->db->getQueryBuilder();
			$qb->delete('cromcull_groups')
				->where($qb->expr()->like('scan_id', $qb->createNamedParameter($pattern)));
			$qb->executeStatement();

			foreach ($groups as $group) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('cromcull_groups')
					->values([
						'scan_id' => $qb->createNamedParameter($scanId),
						'hash' => $qb->createNamedParameter($group['hash']),
						'size' => $qb->createNamedParameter($group['size'], IQueryBuilder::PARAM_INT),
						'file_ids' => $qb->createNamedParameter(json_encode(array_values($group['file_ids']))),
						'protected_ids' => $qb->createNamedParameter(json_encode(array_values($group['protected_ids'] ?? []))),
						'status' => $qb->createNamedParameter('pending'),
					]);
				$qb->executeStatement();
			}

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
