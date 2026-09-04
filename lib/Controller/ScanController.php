<?php

declare(strict_types=1);

namespace OCA\CromCull\Controller;

use OCA\CromCull\AppInfo\Application;
use OCA\CromCull\Service\ScanService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ScanController extends Controller {
	private ScanService $scanService;
	private ?string $userId;

	public function __construct(
		IRequest $request,
		ScanService $scanService,
		?string $userId
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->scanService = $scanService;
		$this->userId = $userId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function stats(): JSONResponse {
		try {
			$result = $this->scanService->getStats($this->userId);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function start(): JSONResponse {
		try {
			$resume = (bool)$this->request->getParam('resume', false);
			$result = $this->scanService->startScan($this->userId, $resume);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function chunk(): JSONResponse {
		try {
			$scanId = $this->request->getParam('scan_id', '');
			$sizes = $this->request->getParam('sizes', []);

			if ($scanId === '' || empty($sizes)) {
				return new JSONResponse(['error' => 'Missing scan_id or sizes'], 400);
			}

			$sizes = array_map('intval', $sizes);
			$result = $this->scanService->processChunk($this->userId, $scanId, $sizes);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function finish(): JSONResponse {
		try {
			$result = $this->scanService->completeScan($this->userId);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}
}
