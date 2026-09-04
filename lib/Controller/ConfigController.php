<?php

declare(strict_types=1);

namespace OCA\CromCull\Controller;

use OCA\CromCull\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

class ConfigController extends Controller {
	private IConfig $config;

	public function __construct(IRequest $request, IConfig $config) {
		parent::__construct(Application::APP_ID, $request);
		$this->config = $config;
	}

	public function get(): JSONResponse {
		return new JSONResponse([
			'min_size' => $this->config->getAppValue(Application::APP_ID, 'min_size', '0'),
			'max_size' => $this->config->getAppValue(Application::APP_ID, 'max_size', '0'),
			'ignored_extensions' => $this->config->getAppValue(Application::APP_ID, 'ignored_extensions', ''),
			'chunk_budget' => $this->config->getAppValue(Application::APP_ID, 'chunk_budget', '104857600'),
		]);
	}

	public function save(): JSONResponse {
		$minSize = $this->request->getParam('min_size', '0');
		$maxSize = $this->request->getParam('max_size', '0');
		$ignoredExtensions = $this->request->getParam('ignored_extensions', '');
		$chunkBudget = $this->request->getParam('chunk_budget', '104857600');

		$minSize = max(0, (int)$minSize);
		$maxSize = max(0, (int)$maxSize);
		$chunkBudget = max(1048576, (int)$chunkBudget);

		if ($maxSize > 0 && $maxSize < $minSize) {
			return new JSONResponse(['error' => 'Max size must be greater than min size'], 400);
		}

		$normalizedExts = '';
		if ($ignoredExtensions !== '') {
			$exts = array_filter(array_map(function ($e) {
				return strtolower(ltrim(trim($e), '.'));
			}, explode(',', $ignoredExtensions)));
			$normalizedExts = implode(',', array_unique($exts));
		}

		$this->config->setAppValue(Application::APP_ID, 'min_size', (string)$minSize);
		$this->config->setAppValue(Application::APP_ID, 'max_size', (string)$maxSize);
		$this->config->setAppValue(Application::APP_ID, 'ignored_extensions', $normalizedExts);
		$this->config->setAppValue(Application::APP_ID, 'chunk_budget', (string)$chunkBudget);

		return new JSONResponse([
			'min_size' => (string)$minSize,
			'max_size' => (string)$maxSize,
			'ignored_extensions' => $normalizedExts,
			'chunk_budget' => (string)$chunkBudget,
		]);
	}
}
