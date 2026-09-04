<?php

declare(strict_types=1);

namespace OCA\CromCull\Controller;

use OCA\CromCull\AppInfo\Application;
use OCA\CromCull\Service\ScanService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

class UserConfigController extends Controller {
	private IConfig $config;
	private ScanService $scanService;
	private ?string $userId;

	public function __construct(
		IRequest $request,
		IConfig $config,
		ScanService $scanService,
		?string $userId
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->config = $config;
		$this->scanService = $scanService;
		$this->userId = $userId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function get(): JSONResponse {
		return new JSONResponse($this->scanService->getEffectiveConfig($this->userId));
	}

	/**
	 * @NoAdminRequired
	 */
	public function save(): JSONResponse {
		$minSize = max(0, (int)$this->request->getParam('min_size', '0'));
		$maxSize = max(0, (int)$this->request->getParam('max_size', '0'));
		$ignoredExtensions = $this->request->getParam('ignored_extensions', '');

		$adminMin = (int)$this->config->getAppValue(Application::APP_ID, 'min_size', '0');
		$adminMax = (int)$this->config->getAppValue(Application::APP_ID, 'max_size', '0');

		if ($minSize > 0 && $minSize < $adminMin) {
			$minSize = $adminMin;
		}

		if ($maxSize > 0) {
			if ($adminMax > 0 && $maxSize > $adminMax) {
				$maxSize = $adminMax;
			}
			if ($maxSize > 0 && $minSize > 0 && $maxSize < $minSize) {
				return new JSONResponse(['error' => 'Max size must be greater than min size'], 400);
			}
		}

		$normalizedExts = '';
		if ($ignoredExtensions !== '') {
			$exts = array_filter(array_map(function ($e) {
				return strtolower(ltrim(trim($e), '.'));
			}, explode(',', $ignoredExtensions)));
			$normalizedExts = implode(',', array_unique($exts));
		}

		$this->config->setUserValue($this->userId, Application::APP_ID, 'user_min_size', (string)$minSize);
		$this->config->setUserValue($this->userId, Application::APP_ID, 'user_max_size', (string)$maxSize);
		$this->config->setUserValue($this->userId, Application::APP_ID, 'user_ignored_extensions', $normalizedExts);

		return new JSONResponse($this->scanService->getEffectiveConfig($this->userId));
	}
}
