<?php

declare(strict_types=1);

namespace OCA\CromCull\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;

class CromcullIgnoreService {
	private const MARKER_NAME = '.cromcull_ignore';

	private IRootFolder $rootFolder;
	private IConfig $config;

	public function __construct(
		IRootFolder $rootFolder,
		IConfig $config
	) {
		$this->rootFolder = $rootFolder;
		$this->config = $config;
	}

	public function getAdminExcludedFolders(string $userId): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$markers = $this->findAllMarkers($userFolder);
		$folders = [];

		foreach ($markers as $marker) {
			if ($marker['valid'] && $marker['admin_excluded']) {
				$folders[] = [
					'path' => $marker['user_path'],
					'folder_id' => $marker['folder_id'],
				];
			} elseif (!$marker['valid']) {
				$folders[] = [
					'path' => $marker['user_path'],
					'folder_id' => $marker['folder_id'],
					'invalid' => true,
				];
			}
		}

		return $folders;
	}

	public function getUserExcludedFolders(string $userId): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$markers = $this->findAllMarkers($userFolder);
		$userFolders = [];
		$adminFolders = [];

		foreach ($markers as $marker) {
			if (!$marker['valid']) {
				continue;
			}
			$entry = [
				'path' => $marker['user_path'],
				'folder_id' => $marker['folder_id'],
			];
			if (in_array($userId, $marker['users'])) {
				$userFolders[] = $entry;
			}
			if ($marker['admin_excluded']) {
				$adminFolders[] = $entry;
			}
		}

		return ['user' => $userFolders, 'admin' => $adminFolders];
	}

	public function addAdminExclusion(string $userId, string $folderPath): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$folder = $userFolder->get($folderPath);
		if (!($folder instanceof Folder)) {
			return ['error' => 'Not a folder'];
		}

		$this->lockedWrite($folder, function (array $config): array {
			$config['admin_excluded'] = true;
			return $config;
		});

		return ['status' => 'ok', 'path' => $folderPath];
	}

	public function removeAdminExclusion(string $userId, string $folderPath): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$folder = $userFolder->get($folderPath);
		if (!($folder instanceof Folder)) {
			return ['error' => 'Not a folder'];
		}

		$this->lockedWrite($folder, function (array $config): array {
			$config['admin_excluded'] = false;
			return $config;
		});

		return ['status' => 'ok', 'path' => $folderPath];
	}

	public function addUserExclusion(string $userId, string $folderPath): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$folder = $userFolder->get($folderPath);
		if (!($folder instanceof Folder)) {
			return ['error' => 'Not a folder'];
		}

		$this->lockedWrite($folder, function (array $config) use ($userId): array {
			if (!in_array($userId, $config['users'])) {
				$config['users'][] = $userId;
			}
			return $config;
		});

		return ['status' => 'ok', 'path' => $folderPath];
	}

	public function removeUserExclusion(string $userId, string $folderPath): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$folder = $userFolder->get($folderPath);
		if (!($folder instanceof Folder)) {
			return ['error' => 'Not a folder'];
		}

		$this->lockedWrite($folder, function (array $config) use ($userId): array {
			$config['users'] = array_values(array_filter(
				$config['users'],
				fn($u) => $u !== $userId
			));
			return $config;
		});

		return ['status' => 'ok', 'path' => $folderPath];
	}

	private function lockedWrite(Folder $folder, callable $modifier): void {
		$lockDir = $this->getLockDir();
		if (!is_dir($lockDir)) {
			mkdir($lockDir, 0750, true);
		}

		$folderId = $folder->getId();
		$lockFile = $lockDir . '/ignore_' . $folderId . '.lock';
		$lockHandle = fopen($lockFile, 'c');
		if (!$lockHandle) {
			throw new \RuntimeException('Could not open lock file');
		}

		if (!flock($lockHandle, LOCK_EX)) {
			fclose($lockHandle);
			throw new \RuntimeException('Could not acquire lock');
		}

		try {
			$config = [
				'admin_excluded' => false,
				'users' => [],
				'has_explanation' => false,
			];

			$existingFile = null;
			try {
				$existingFile = $folder->get(self::MARKER_NAME);
				if ($existingFile instanceof File) {
					$parsed = CromcullIgnoreParser::parse($existingFile->getContent());
					if ($parsed['valid']) {
						$config = $parsed;
					}
				}
			} catch (NotFoundException $e) {
				// No existing file
			}

			$config = $modifier($config);

			if (!$config['admin_excluded'] && empty($config['users'])) {
				if ($existingFile instanceof File) {
					$existingFile->delete();
				}
			} else {
				$content = $this->generateContent($config['admin_excluded'], $config['users']);
				if ($existingFile instanceof File) {
					$existingFile->putContent($content);
				} else {
					$folder->newFile(self::MARKER_NAME, $content);
				}
			}
		} finally {
			flock($lockHandle, LOCK_UN);
			fclose($lockHandle);
		}
	}

	private function findAllMarkers(Folder $userFolder): array {
		$nodes = $userFolder->search(self::MARKER_NAME);
		$markers = [];

		foreach ($nodes as $node) {
			if (!($node instanceof File) || $node->getName() !== self::MARKER_NAME) {
				continue;
			}

			$parent = $node->getParent();
			$userPath = $userFolder->getRelativePath($parent->getPath());
			if ($userPath === null || $userPath === '' || $userPath === '/') {
				continue;
			}

			try {
				$config = CromcullIgnoreParser::parse($node->getContent());
			} catch (\Exception $e) {
				$config = ['valid' => false, 'admin_excluded' => false, 'users' => [], 'has_explanation' => false];
			}

			if ($config['valid']) {
				$expected = $this->generateContent($config['admin_excluded'], $config['users']);
				if ($node->getContent() !== $expected) {
					try {
						$node->putContent($expected);
					} catch (\Exception $e) {
					}
				}
			}

			$markers[] = [
				'file_id' => $node->getId(),
				'folder_id' => $parent->getId(),
				'user_path' => $userPath,
				'valid' => $config['valid'],
				'admin_excluded' => $config['admin_excluded'],
				'users' => $config['users'],
			];
		}

		return $markers;
	}

	private function generateContent(bool $adminExcluded, array $users): string {
		$lines = [];
		$lines[] = 'admin_excluded=' . ($adminExcluded ? 'true' : 'false');
		$lines[] = 'users=' . implode(',', $users);
		$lines[] = '';
		$lines[] = 'This folder is excluded from CromCull duplicate detection scans.';
		$lines[] = 'Files in this folder and its subfolders will not appear in scan';
		$lines[] = 'results. To re-enable scanning, remove this file or use the';
		$lines[] = 'CromCull settings to manage folder exclusions.';
		return implode("\n", $lines) . "\n";
	}

	private function getLockDir(): string {
		$dataDir = $this->config->getSystemValue('datadirectory', '/var/www/nextcloud/data');
		$instanceId = $this->config->getSystemValue('instanceid', '');
		return $dataDir . '/appdata_' . $instanceId . '/cromcull/locks';
	}

}
