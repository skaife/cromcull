<?php

declare(strict_types=1);

namespace OCA\CromCull\Command;

use OCA\CromCull\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command {
	private IDBConnection $db;
	private IConfig $config;

	public function __construct(IDBConnection $db, IConfig $config) {
		parent::__construct();
		$this->db = $db;
		$this->config = $config;
	}

	protected function configure(): void {
		$this->setName('cromcull:status')
			->setDescription('Show CromCull scan state, group counts, and cache stats')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Show data for a specific user');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getOption('user');

		$output->writeln('<info>CromCull Status</info>');
		$output->writeln('');

		$this->showHashCache($output);
		$this->showScanCache($output, $user);
		$this->showGroups($output, $user);
		$this->showHiddenHashes($output, $user);

		return 0;
	}

	private function showHashCache(OutputInterface $output): void {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'cnt')
			->from('cromcull_hash_cache');
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'cnt')
			->from('cromcull_hash_cache')
			->where($qb->expr()->isNotNull('full_hash'));
		$result = $qb->executeQuery();
		$withFull = (int)$result->fetchOne();
		$result->closeCursor();

		$output->writeln('<comment>Hash Cache</comment>');
		$output->writeln("  Total entries:       $total");
		$output->writeln("  With full hash:      $withFull");
		$output->writeln("  Partial only:        " . ($total - $withFull));
		$output->writeln('');
	}

	private function showScanCache(OutputInterface $output, ?string $user): void {
		$output->writeln('<comment>Scan Cache Stats</comment>');

		if ($user === null) {
			$output->writeln('  Specify --user to see scan cache hit/miss stats');
			$output->writeln('');
			return;
		}

		$raw = $this->config->getUserValue($user, Application::APP_ID, 'scan_state', '');
		if ($raw === '') {
			$output->writeln("  No scan state for user $user");
			$output->writeln('');
			return;
		}

		$state = json_decode($raw, true);
		if (!is_array($state)) {
			$output->writeln("  Invalid scan state for user $user");
			$output->writeln('');
			return;
		}

		$hits = $state['cache_hits'] ?? 0;
		$misses = $state['cache_misses'] ?? 0;
		$total = $hits + $misses;
		$rate = $total > 0 ? round(($hits / $total) * 100, 1) : 0;
		$status = $state['status'] ?? 'unknown';
		$scanId = $state['scan_id'] ?? 'unknown';

		$output->writeln("  Scan:                $scanId");
		$output->writeln("  Status:              $status");
		$output->writeln("  Cache hits:          $hits");
		$output->writeln("  Cache misses:        $misses");
		$output->writeln("  Hit rate:            $rate%");
		$output->writeln('');
	}

	private function showGroups(OutputInterface $output, ?string $user): void {
		$output->writeln('<comment>Duplicate Groups</comment>');

		$qb = $this->db->getQueryBuilder();
		$qb->select('scan_id', 'status')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from('cromcull_groups')
			->groupBy('scan_id', 'status')
			->orderBy('scan_id');

		if ($user !== null) {
			$pattern = '%' . $this->db->escapeLikeParameter('_' . $user);
			$qb->andWhere($qb->expr()->like('scan_id', $qb->createNamedParameter($pattern)));
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		if (empty($rows)) {
			$output->writeln('  No groups found');
			$output->writeln('');
			return;
		}

		$byScan = [];
		foreach ($rows as $row) {
			$byScan[$row['scan_id']][$row['status']] = (int)$row['cnt'];
		}

		foreach ($byScan as $scanId => $statuses) {
			$total = array_sum($statuses);
			$output->writeln("  Scan: $scanId");
			$output->writeln("    Total groups:      $total");
			foreach ($statuses as $status => $count) {
				$output->writeln("    $status:  $count");
			}
		}
		$output->writeln('');
	}

	private function showHiddenHashes(OutputInterface $output, ?string $user): void {
		$output->writeln('<comment>Hidden Hashes</comment>');

		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from('cromcull_hidden_hashes')
			->groupBy('user_id');

		if ($user !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($user)));
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		if (empty($rows)) {
			$output->writeln('  No hidden hashes');
			$output->writeln('');
			return;
		}

		foreach ($rows as $row) {
			$output->writeln("  User: {$row['user_id']}  —  {$row['cnt']} hidden");
		}
		$output->writeln('');
	}
}
