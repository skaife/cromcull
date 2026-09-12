<?php

declare(strict_types=1);

namespace OCA\CromCull\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class HashCacheService {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	public function lookup(array $fileIds): array {
		$cache = [];
		foreach (array_chunk($fileIds, 100) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$params = [];
			foreach ($chunk as $id) {
				$params[] = $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT);
			}
			$qb->select('fileid', 'etag', 'size', 'partial_hash', 'full_hash')
				->from('cromcull_hash_cache')
				->where($qb->expr()->in('fileid', $params));

			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$cache[(int)$row['fileid']] = [
					'etag' => $row['etag'],
					'size' => (int)$row['size'],
					'partial_hash' => $row['partial_hash'],
					'full_hash' => $row['full_hash'],
				];
			}
			$result->closeCursor();
		}
		return $cache;
	}

	public function upsertPartial(int $fileId, string $etag, int $size, string $partialHash): void {
		$existing = $this->get($fileId);
		if ($existing !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('cromcull_hash_cache')
				->set('etag', $qb->createNamedParameter($etag))
				->set('size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT))
				->set('partial_hash', $qb->createNamedParameter($partialHash))
				->set('full_hash', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		} else {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('cromcull_hash_cache')
				->values([
					'fileid' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
					'etag' => $qb->createNamedParameter($etag),
					'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
					'partial_hash' => $qb->createNamedParameter($partialHash),
				]);
			$qb->executeStatement();
		}
	}

	public function upsertFull(int $fileId, string $etag, int $size, string $partialHash, string $fullHash): void {
		$existing = $this->get($fileId);
		if ($existing !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('cromcull_hash_cache')
				->set('etag', $qb->createNamedParameter($etag))
				->set('size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT))
				->set('partial_hash', $qb->createNamedParameter($partialHash))
				->set('full_hash', $qb->createNamedParameter($fullHash))
				->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		} else {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('cromcull_hash_cache')
				->values([
					'fileid' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
					'etag' => $qb->createNamedParameter($etag),
					'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
					'partial_hash' => $qb->createNamedParameter($partialHash),
					'full_hash' => $qb->createNamedParameter($fullHash),
				]);
			$qb->executeStatement();
		}
	}

	public function clearByFileIds(array $fileIds): int {
		$deleted = 0;
		foreach (array_chunk($fileIds, 100) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$params = [];
			foreach ($chunk as $id) {
				$params[] = $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT);
			}
			$qb->delete('cromcull_hash_cache')
				->where($qb->expr()->in('fileid', $params));
			$deleted += $qb->executeStatement();
		}
		return $deleted;
	}

	public function clearAll(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('cromcull_hash_cache');
		return $qb->executeStatement();
	}

	public function garbageCollect(): int {
		$deleted = 0;

		$qb = $this->db->getQueryBuilder();
		$qb->delete('cromcull_hash_cache')
			->where($qb->expr()->notIn(
				'fileid',
				$qb->createFunction('SELECT `fileid` FROM `*PREFIX*filecache`')
			));
		$deleted += $qb->executeStatement();

		$qb2 = $this->db->getQueryBuilder();
		$qb2->selectDistinct('fc.storage')
			->from('cromcull_hash_cache', 'hc')
			->innerJoin('hc', 'filecache', 'fc', $qb2->expr()->eq('hc.fileid', 'fc.fileid'))
			->leftJoin('fc', 'mounts', 'm', $qb2->expr()->eq('fc.storage', 'm.storage_id'))
			->where($qb2->expr()->isNull('m.storage_id'));
		$result = $qb2->executeQuery();
		$unmountedStorages = [];
		while ($row = $result->fetch()) {
			$unmountedStorages[] = (int)$row['storage'];
		}
		$result->closeCursor();

		foreach ($unmountedStorages as $storageId) {
			$qb3 = $this->db->getQueryBuilder();
			$qb3->delete('cromcull_hash_cache')
				->where($qb3->expr()->in(
					'fileid',
					$qb3->createFunction(
						'SELECT `fileid` FROM `*PREFIX*filecache` WHERE `storage` = ' . (int)$storageId
					)
				));
			$deleted += $qb3->executeStatement();
		}

		return $deleted;
	}

	public function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'cnt')
			->from('cromcull_hash_cache');
		$result = $qb->executeQuery();
		$cnt = (int)$result->fetchOne();
		$result->closeCursor();
		return $cnt;
	}

	private function get(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('cromcull_hash_cache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row ?: null;
	}
}
