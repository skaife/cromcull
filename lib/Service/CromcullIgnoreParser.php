<?php

declare(strict_types=1);

namespace OCA\CromCull\Service;

use OCP\Files\File;
use OCP\Files\Folder;

class CromcullIgnoreParser {

	public static function parse(string $content): array {
		$result = [
			'valid' => false,
			'admin_excluded' => false,
			'users' => [],
			'has_explanation' => false,
		];

		$lines = explode("\n", $content);
		$foundAdminExcluded = false;
		$headerEnded = false;

		foreach ($lines as $line) {
			$trimmed = trim($line);

			if (!$headerEnded) {
				if ($trimmed === '' || str_starts_with($trimmed, '#')) {
					continue;
				}

				if (!str_contains($trimmed, '=')) {
					$headerEnded = true;
					if ($trimmed !== '') {
						$result['has_explanation'] = true;
					}
					continue;
				}

				[$key, $value] = explode('=', $trimmed, 2);
				$key = trim(strtolower($key));
				$value = trim($value);

				if ($key === 'admin_excluded') {
					$foundAdminExcluded = true;
					$result['admin_excluded'] = strtolower($value) === 'true';
				} elseif ($key === 'users') {
					if ($value !== '') {
						$result['users'] = array_values(array_filter(array_map('trim', explode(',', $value))));
					}
				} else {
					$headerEnded = true;
					$result['has_explanation'] = true;
					continue;
				}
			} else {
				if ($trimmed !== '') {
					$result['has_explanation'] = true;
					break;
				}
			}
		}

		$result['valid'] = $foundAdminExcluded;

		return $result;
	}

	public static function readAndParse(Folder $userFolder, int $fileId): array {
		try {
			$nodes = $userFolder->getById($fileId);
			if (empty($nodes) || !($nodes[0] instanceof File)) {
				return self::invalid();
			}
			$content = $nodes[0]->getContent();
			return self::parse($content);
		} catch (\Exception $e) {
			return self::invalid();
		}
	}

	private static function invalid(): array {
		return [
			'valid' => false,
			'admin_excluded' => false,
			'users' => [],
			'has_explanation' => false,
		];
	}
}
