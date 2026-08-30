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
	public function scan(): JSONResponse {
		try {
			$result = $this->scanService->scan($this->userId);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(
				['error' => $e->getMessage()],
				500
			);
		}
	}
}
