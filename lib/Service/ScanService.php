<?php

declare(strict_types=1);

namespace OCA\CromCull\Service;

use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCA\CromCull\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\Search\ISearchComparison;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

class ScanService {
	private const PARTIAL_HASH_BYTES = 65536;
	private const HASH_ALGO = 'sha256';
	private const DEFAULT_CHUNK_BUDGET = 52428800;

	private IDBConnection $db;
	private IRootFolder $rootFolder;
	private IConfig $config;
	private IgnoreBaselineService $baselineService;
	private IMountManager $mountManager;
	private IShareManager $shareManager;
	private HashCacheService $hashCache;

	public function __construct(
		IDBConnection $db,
		IRootFolder $rootFolder,
		IConfig $config,
		IgnoreBaselineService $baselineService,
		IMountManager $mountManager,
		IShareManager $shareManager,
		HashCacheService $hashCache
	) {
		$this->db = $db;
		$this->rootFolder = $rootFolder;
		$this->config = $config;
		$this->baselineService = $baselineService;
		$this->mountManager = $mountManager;
		$this->shareManager = $shareManager;
		$this->hashCache = $hashCache;
	}

	public function getEffectiveConfig(string $userId): array {
		$adminMin = (int)$this->config->getAppValue(Application::APP_ID, 'min_size', '0');
		$adminMax = (int)$this->config->getAppValue(Application::APP_ID, 'max_size', '0');
		$adminExts = $this->config->getAppValue(Application::APP_ID, 'ignored_extensions', '');

		$userMin = (int)$this->config->getUserValue($userId, Application::APP_ID, 'user_min_size', '0');
		$userMax = (int)$this->config->getUserValue($userId, Application::APP_ID, 'user_max_size', '0');
		$userExts = $this->config->getUserValue($userId, Application::APP_ID, 'user_ignored_extensions', '');

		$effectiveMin = max($adminMin, $userMin);

		$effectiveMax = 0;
		if ($adminMax > 0 && $userMax > 0) {
			$effectiveMax = min($adminMax, $userMax);
		} elseif ($adminMax > 0) {
			$effectiveMax = $adminMax;
		} elseif ($userMax > 0) {
			$effectiveMax = $userMax;
		}

		$adminExtList = $adminExts !== '' ? array_filter(array_map('trim', explode(',', $adminExts))) : [];
		$userExtList = $userExts !== '' ? array_filter(array_map('trim', explode(',', $userExts))) : [];
		$effectiveExts = array_values(array_unique(array_merge($adminExtList, $userExtList)));
		sort($effectiveExts);

		return [
			'min_size' => $effectiveMin,
			'max_size' => $effectiveMax,
			'ignored_extensions' => $effectiveExts,
			'admin' => [
				'min_size' => $adminMin,
				'max_size' => $adminMax,
				'ignored_extensions' => $adminExts,
			],
			'user' => [
				'min_size' => $userMin,
				'max_size' => $userMax,
				'ignored_extensions' => $userExts,
			],
		];
	}

	public function getStats(string $userId): array {
		$config = $this->getEffectiveConfig($userId);
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
				->andWhere($qb->expr()->gte('size', $qb->createNamedParameter($config['min_size'], IQueryBuilder::PARAM_INT)))
				->groupBy('size');

			if ($mount['root_path'] !== '') {
				$escapedRoot = $this->db->escapeLikeParameter($mount['root_path']);
				$qb->andWhere($qb->expr()->like('path', $qb->createNamedParameter($escapedRoot . '/%')));
			}

			if ($config['max_size'] > 0) {
				$qb->andWhere($qb->expr()->lte('size', $qb->createNamedParameter($config['max_size'], IQueryBuilder::PARAM_INT)));
			}

			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$size = (int)$row['size'];
				$sizeCounts[$size] = ($sizeCounts[$size] ?? 0) + (int)$row['cnt'];
			}
			$result->closeCursor();
		}

		$totalFiles = array_sum($sizeCounts);

		$candidateSizes = [];
		$candidateFiles = 0;
		foreach ($sizeCounts as $size => $count) {
			if ($count >= 2) {
				$candidateSizes[] = ['size' => $size, 'count' => $count];
				$candidateFiles += $count;
			}
		}

		usort($candidateSizes, fn($a, $b) => $a['size'] <=> $b['size']);

		$scanState = $this->getScanState($userId);

		$chunkBudget = (int)$this->config->getAppValue(Application::APP_ID, 'chunk_budget', (string)self::DEFAULT_CHUNK_BUDGET);
		if ($chunkBudget <= 0) {
			$chunkBudget = self::DEFAULT_CHUNK_BUDGET;
		}

		return [
			'total_files' => $totalFiles,
			'candidate_files' => $candidateFiles,
			'candidate_sizes' => $candidateSizes,
			'chunk_budget' => $chunkBudget,
			'incomplete_scan' => $scanState,
			'config' => $config,
		];
	}

	public function startScan(string $userId): array {
		$this->hashCache->garbageCollect();

		$scanId = date('c') . '_' . $userId;

		$pattern = '%' . $this->db->escapeLikeParameter('_' . $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->delete('cromcull_groups')
			->where($qb->expr()->like('scan_id', $qb->createNamedParameter($pattern)));
		$qb->executeStatement();

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$storageMounts = $this->getUserStorageMounts($userId);
		$ignoredFolders = $this->getIgnoredFolderPaths($userFolder, $userId);

		$this->setScanState($userId, [
			'scan_id' => $scanId,
			'status' => 'incomplete',
			'processed_sizes' => [],
			'started_at' => date('c'),
			'cache_hits' => 0,
			'cache_misses' => 0,
			'storage_mounts' => $storageMounts,
			'ignored_folders' => $this->serializeIgnoredFolders($ignoredFolders),
		]);

		return [
			'scan_id' => $scanId,
		];
	}

	public function processChunk(string $userId, string $scanId, array $sizes): array {
		$config = $this->getEffectiveConfig($userId);
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$dirMimeId = $this->getDirectoryMimeTypeId();
		$ignoredExtensions = !empty($config['ignored_extensions']) ? array_flip($config['ignored_extensions']) : [];

		$state = $this->getScanState($userId);
		$storageMounts = $state['storage_mounts'] ?? $this->getUserStorageMounts($userId);
		$ignoredFolders = isset($state['ignored_folders'])
			? $this->deserializeIgnoredFolders($state['ignored_folders'])
			: $this->getIgnoredFolderPaths($userFolder, $userId);

		$candidates = [];
		foreach ($storageMounts as $mount) {
			foreach (array_chunk($sizes, 100) as $sizeChunk) {
				$qb = $this->db->getQueryBuilder();
				$qb->select('fileid', 'path', 'name', 'size', 'etag')
					->from('filecache')
					->where($qb->expr()->eq('storage', $qb->createNamedParameter($mount['storage_id'], IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter($dirMimeId, IQueryBuilder::PARAM_INT)));

				if ($mount['root_path'] !== '') {
					$escapedRoot = $this->db->escapeLikeParameter($mount['root_path']);
					$qb->andWhere($qb->expr()->like('path', $qb->createNamedParameter($escapedRoot . '/%')));
				}

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
						'size' => $size,
						'etag' => $row['etag'],
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
		$filesProcessed = 0;
		$cacheHits = 0;
		$cacheMisses = 0;

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

			$filesProcessed += count($files);

			if (count($files) < 2) {
				continue;
			}

			$partialResult = $this->computePartialHashes($userFolder, $files);
			$cacheHits += $partialResult['hits'];
			$cacheMisses += $partialResult['misses'];

			foreach ($partialResult['groups'] as $partialFiles) {
				if (count($partialFiles) < 2) {
					continue;
				}

				$fullResult = $this->computeFullHashes($userFolder, $partialFiles, $partialResult['cached']);
				$cacheHits += $fullResult['hits'];
				$cacheMisses += $fullResult['misses'];

				foreach ($fullResult['groups'] as $fullHash => $matchedFiles) {
					if (count($matchedFiles) < 2) {
						continue;
					}
					$groups[] = [
						'hash' => $fullHash,
						'size' => $size,
						'file_ids' => array_map(fn($f) => $f['fileid'], $matchedFiles),
					];
				}
			}
		}

		foreach ($groups as &$group) {
			$group['protected_ids'] = [];
		}
		unset($group);

		if (!empty($groups)) {
			$this->appendResults($scanId, $groups);
		}

		if ($state !== null) {
			$state['processed_sizes'] = array_values(array_unique(
				array_merge($state['processed_sizes'] ?? [], $sizes)
			));
			$state['cache_hits'] = ($state['cache_hits'] ?? 0) + $cacheHits;
			$state['cache_misses'] = ($state['cache_misses'] ?? 0) + $cacheMisses;
			$this->setScanState($userId, $state);
		}

		return [
			'groups_found' => count($groups),
			'files_processed' => $filesProcessed,
			'cache_hits' => $cacheHits,
			'cache_misses' => $cacheMisses,
		];
	}

	public function completeScan(string $userId): array {
		$this->clearScanState($userId);
		$drift = $this->baselineService->checkAndUpdateBaseline($userId);
		return ['drift' => $drift];
	}

	public function recheckGroup(string $userId, int $groupId): array {
		$qb = $this->db->getQueryBuilder();
		$pattern = '%' . $this->db->escapeLikeParameter('_' . $userId);
		$qb->select('*')
			->from('cromcull_groups')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('scan_id', $qb->createNamedParameter($pattern)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (!$row) {
			return ['status' => 'not_found'];
		}

		$fileIds = json_decode($row['file_ids'], true);
		$expectedHash = $row['hash'];
		$size = (int)$row['size'];

		$this->hashCache->clearByFileIds($fileIds);

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$matched = [];
		$protectedIds = [];

		foreach ($fileIds as $fileId) {
			try {
				$nodes = $userFolder->getById($fileId);
				if (empty($nodes) || !($nodes[0] instanceof File)) {
					continue;
				}
				$node = $nodes[0];

				$handle = $node->fopen('r');
				if (!$handle) {
					continue;
				}
				$ctx = hash_init(self::HASH_ALGO);
				while (!feof($handle)) {
					hash_update($ctx, fread($handle, 8192));
				}
				fclose($handle);
				$hash = hash_final($ctx);

				if ($hash === $expectedHash) {
					$matched[] = $fileId;

					$storage = $node->getStorage();
					$etag = '';
					$fcQb = $this->db->getQueryBuilder();
					$fcQb->select('etag')
						->from('filecache')
						->where($fcQb->expr()->eq('fileid', $fcQb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
					$fcResult = $fcQb->executeQuery();
					$etagRow = $fcResult->fetchOne();
					$fcResult->closeCursor();
					if ($etagRow !== false) {
						$etag = $etagRow;
					}

					$partialHandle = $node->fopen('r');
					if ($partialHandle) {
						$chunk = fread($partialHandle, self::PARTIAL_HASH_BYTES);
						fclose($partialHandle);
						$partialHash = hash(self::HASH_ALGO, $chunk);
						$this->hashCache->upsertFull($fileId, $etag, $size, $partialHash, $hash);
					}

					if ($this->isFileProtected($node, $userId, $userFolder)) {
						$protectedIds[] = $fileId;
					}
				}
			} catch (\Exception $e) {
				continue;
			}
		}

		if (count($matched) < 2) {
			$delQb = $this->db->getQueryBuilder();
			$delQb->delete('cromcull_groups')
				->where($delQb->expr()->eq('id', $delQb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));
			$delQb->executeStatement();
			return ['status' => 'removed'];
		}

		$updQb = $this->db->getQueryBuilder();
		$updQb->update('cromcull_groups')
			->set('file_ids', $updQb->createNamedParameter(json_encode(array_values($matched))))
			->set('protected_ids', $updQb->createNamedParameter(json_encode(array_values($protectedIds))))
			->where($updQb->expr()->eq('id', $updQb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));
		$updQb->executeStatement();

		return ['status' => 'verified', 'members' => count($matched)];
	}

	public function getScanState(string $userId): ?array {
		$raw = $this->config->getUserValue($userId, Application::APP_ID, 'scan_state', '');
		if ($raw === '') {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	private function setScanState(string $userId, array $state): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'scan_state', json_encode($state));
	}

	private function clearScanState(string $userId): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'scan_state', '');
	}

	private function serializeIgnoredFolders(array $ignoredFolders): array {
		$serialized = [];
		foreach ($ignoredFolders as $storageId => $paths) {
			$serialized[] = ['storage_id' => $storageId, 'paths' => $paths];
		}
		return $serialized;
	}

	private function deserializeIgnoredFolders(array $serialized): array {
		$result = [];
		foreach ($serialized as $entry) {
			$result[(int)$entry['storage_id']] = $entry['paths'];
		}
		return $result;
	}

	private function appendResults(string $scanId, array $groups): void {
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

	private function getIgnoredFolderPaths(Folder $userFolder, string $userId): array {
		$query = new SearchQuery(
			new SearchComparison(ISearchComparison::COMPARE_EQUAL, 'name', '.cromcull_ignore'),
			0, 0, []
		);
		$nodes = $userFolder->search($query);
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
		$fileIds = array_map(fn($f) => $f['fileid'], $files);
		$cached = $this->hashCache->lookup($fileIds);

		$groups = [];
		$hits = 0;
		$misses = 0;
		foreach ($files as $file) {
			$fid = $file['fileid'];
			$size = (int)$file['size'];
			$etag = $file['etag'];

			if (isset($cached[$fid])
				&& $cached[$fid]['etag'] === $etag
				&& $cached[$fid]['size'] === $size
			) {
				$hash = $cached[$fid]['partial_hash'];
				$groups[$hash][] = $file;
				$hits++;
				continue;
			}

			$misses++;
			try {
				$nodes = $userFolder->getById($fid);
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
				$this->hashCache->upsertPartial($fid, $etag, $size, $hash);
				$groups[$hash][] = $file;
			} catch (\Exception $e) {
				continue;
			}
		}
		return ['groups' => $groups, 'hits' => $hits, 'misses' => $misses, 'cached' => $cached];
	}

	private function computeFullHashes($userFolder, array $files, array $prefetchedCache = []): array {
		if (!empty($prefetchedCache)) {
			$cached = $prefetchedCache;
		} else {
			$fileIds = array_map(fn($f) => $f['fileid'], $files);
			$cached = $this->hashCache->lookup($fileIds);
		}

		$groups = [];
		$hits = 0;
		$misses = 0;
		foreach ($files as $file) {
			$fid = $file['fileid'];
			$size = (int)$file['size'];
			$etag = $file['etag'];

			if (isset($cached[$fid])
				&& $cached[$fid]['etag'] === $etag
				&& $cached[$fid]['size'] === $size
				&& $cached[$fid]['full_hash'] !== null
			) {
				$hash = $cached[$fid]['full_hash'];
				$groups[$hash][] = $file;
				$hits++;
				continue;
			}

			$misses++;
			try {
				$nodes = $userFolder->getById($fid);
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
				$partialHash = $cached[$fid]['partial_hash'] ?? $hash;
				$this->hashCache->upsertFull($fid, $etag, $size, $partialHash, $hash);
				$groups[$hash][] = $file;
			} catch (\Exception $e) {
				continue;
			}
		}
		return ['groups' => $groups, 'hits' => $hits, 'misses' => $misses];
	}

	public function isFileProtected($node, string $userId, $userFolder, array &$shareCache = []): bool {
		$storage = $node->getStorage();
		if ($storage->instanceOfStorage(\OCA\GroupFolders\Mount\GroupFolderStorage::class)) {
			return true;
		}
		if ($storage->instanceOfStorage(\OCA\Files_Sharing\SharedStorage::class)) {
			return true;
		}

		$current = $node;
		while ($current !== null) {
			$nodeId = $current->getId();
			if (!array_key_exists($nodeId, $shareCache)) {
				$shareCache[$nodeId] = $this->hasShares($current, $userId);
			}
			if ($shareCache[$nodeId]) {
				return true;
			}
			if ($nodeId === $userFolder->getId()) {
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
}
