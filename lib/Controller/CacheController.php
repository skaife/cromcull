<?php

declare(strict_types=1);

namespace OCA\CromCull\Controller;

use OCA\CromCull\AppInfo\Application;
use OCA\CromCull\Service\HashCacheService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class CacheController extends Controller {
	private HashCacheService $cacheService;

	public function __construct(
		IRequest $request,
		HashCacheService $cacheService
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->cacheService = $cacheService;
	}

	public function clear(): JSONResponse {
		$deleted = $this->cacheService->clearAll();
		return new JSONResponse(['cleared' => $deleted]);
	}

	public function stats(): JSONResponse {
		return new JSONResponse(['count' => $this->cacheService->count()]);
	}
}
