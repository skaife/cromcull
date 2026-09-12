<?php

declare(strict_types=1);

namespace OCA\CromCull\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001200Date20260908000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('cromcull_hash_cache')) {
			$table = $schema->createTable('cromcull_hash_cache');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('fileid', 'integer', [
				'notnull' => true,
			]);
			$table->addColumn('etag', 'string', [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('size', 'bigint', [
				'notnull' => true,
			]);
			$table->addColumn('partial_hash', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('full_hash', 'string', [
				'notnull' => false,
				'length' => 64,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['fileid'], 'cromcull_hc_fileid_idx');
		}

		if (!$schema->hasTable('cromcull_hidden_hashes')) {
			$table = $schema->createTable('cromcull_hidden_hashes');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('hash', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('example_path', 'string', [
				'notnull' => true,
				'length' => 4000,
			]);
			$table->addColumn('size', 'bigint', [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'hash'], 'cromcull_hh_user_hash_idx');
		}

		return $schema;
	}
}
