<?php

declare(strict_types=1);

namespace OCA\CromCull\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class HiddenHashService {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	public function getHiddenHashes(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('hash', 'example_path', 'size')
			->from('cromcull_hidden_hashes')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$hashes = [];
		while ($row = $result->fetch()) {
			$hashes[$row['hash']] = [
				'example_path' => $row['example_path'],
				'size' => (int)$row['size'],
			];
		}
		$result->closeCursor();
		return $hashes;
	}

	public function getHiddenHashSet(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('hash')
			->from('cromcull_hidden_hashes')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$set = [];
		while ($row = $result->fetch()) {
			$set[$row['hash']] = true;
		}
		$result->closeCursor();
		return $set;
	}

	public function hide(string $userId, string $hash, string $examplePath, int $size): void {
		$existing = $this->isHidden($userId, $hash);
		if ($existing) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert('cromcull_hidden_hashes')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'hash' => $qb->createNamedParameter($hash),
				'example_path' => $qb->createNamedParameter($examplePath),
				'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
			]);
		$qb->executeStatement();
	}

	public function unhide(string $userId, string $hash): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('cromcull_hidden_hashes')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('hash', $qb->createNamedParameter($hash)));
		return $qb->executeStatement() > 0;
	}

	public function isHidden(string $userId, string $hash): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'cnt')
			->from('cromcull_hidden_hashes')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('hash', $qb->createNamedParameter($hash)));

		$result = $qb->executeQuery();
		$cnt = (int)$result->fetchOne();
		$result->closeCursor();
		return $cnt > 0;
	}
}
