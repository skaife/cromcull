<?php

declare(strict_types=1);

namespace OCA\CromCull\Controller;

use OCA\CromCull\AppInfo\Application;
use OCA\CromCull\Service\CromcullIgnoreService;
use OCA\CromCull\Service\IgnoreBaselineService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class IgnoreController extends Controller {
	private CromcullIgnoreService $ignoreService;
	private IgnoreBaselineService $baselineService;
	private ?string $userId;

	public function __construct(
		IRequest $request,
		CromcullIgnoreService $ignoreService,
		IgnoreBaselineService $baselineService,
		?string $userId
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->ignoreService = $ignoreService;
		$this->baselineService = $baselineService;
		$this->userId = $userId;
	}

	public function adminList(): JSONResponse {
		$markers = $this->ignoreService->getAllParsedMarkers($this->userId);
		$folders = $this->ignoreService->getAdminExcludedFolders($this->userId, $markers);
		$this->baselineService->checkAndUpdateBaselineFromMarkers($this->userId, $markers);
		return new JSONResponse([
			'folders' => $folders,
		]);
	}

	public function adminAdd(): JSONResponse {
		$path = $this->request->getParam('path', '');
		if ($path === '') {
			return new JSONResponse(['error' => 'Path is required'], 400);
		}
		try {
			$result = $this->ignoreService->addAdminExclusion($this->userId, $path);
			if (isset($result['error'])) {
				return new JSONResponse($result, 400);
			}
			$this->baselineService->updateBaseline($this->userId);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => 'Failed to add exclusion'], 500);
		}
	}

	public function adminRemove(): JSONResponse {
		$path = $this->request->getParam('path', '');
		if ($path === '') {
			return new JSONResponse(['error' => 'Path is required'], 400);
		}
		try {
			$result = $this->ignoreService->removeAdminExclusion($this->userId, $path);
			if (isset($result['error'])) {
				return new JSONResponse($result, 400);
			}
			$this->baselineService->updateBaseline($this->userId);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => 'Failed to remove exclusion'], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function userList(): JSONResponse {
		$markers = $this->ignoreService->getAllParsedMarkers($this->userId);
		$result = $this->ignoreService->getUserExcludedFolders($this->userId, $markers);
		$this->baselineService->checkAndUpdateBaselineFromMarkers($this->userId, $markers);
		return new JSONResponse([
			'folders' => $result['user'],
			'adminFolders' => $result['admin'],
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function userAdd(): JSONResponse {
		$path = $this->request->getParam('path', '');
		if ($path === '') {
			return new JSONResponse(['error' => 'Path is required'], 400);
		}
		try {
			$result = $this->ignoreService->addUserExclusion($this->userId, $path);
			if (isset($result['error'])) {
				return new JSONResponse($result, 400);
			}
			$this->baselineService->updateBaseline($this->userId);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => 'Failed to add exclusion'], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function userRemove(): JSONResponse {
		$path = $this->request->getParam('path', '');
		if ($path === '') {
			return new JSONResponse(['error' => 'Path is required'], 400);
		}
		try {
			$result = $this->ignoreService->removeUserExclusion($this->userId, $path);
			if (isset($result['error'])) {
				return new JSONResponse($result, 400);
			}
			$this->baselineService->updateBaseline($this->userId);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => 'Failed to remove exclusion'], 500);
		}
	}
}
