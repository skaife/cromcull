<?php

declare(strict_types=1);

namespace OCA\CromCull\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000Date20260814000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('cromcull_groups')) {
			$table = $schema->createTable('cromcull_groups');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('scan_id', 'string', [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('hash', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('size', 'bigint', [
				'notnull' => true,
			]);
			$table->addColumn('file_ids', 'text', [
				'notnull' => true,
			]);
			$table->addColumn('protected_ids', 'text', [
				'notnull' => true,
				'default' => '[]',
			]);
			$table->addColumn('status', 'string', [
				'notnull' => true,
				'length' => 20,
				'default' => 'pending',
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['scan_id'], 'cromcull_grp_scan_idx');
			$table->addIndex(['status'], 'cromcull_grp_status_idx');
		}

		return $schema;
	}
}
