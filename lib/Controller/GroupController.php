<?php

declare(strict_types=1);

namespace OCA\CromCull\Controller;

use OCA\CromCull\AppInfo\Application;
use OCA\CromCull\Service\DeleteService;
use OCA\CromCull\Service\HiddenHashService;
use OCA\CromCull\Service\ScanService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IRequest;

class GroupController extends Controller {
	private IDBConnection $db;
	private IRootFolder $rootFolder;
	private DeleteService $deleteService;
	private HiddenHashService $hiddenHashService;
	private ScanService $scanService;
	private ?string $userId;

	public function __construct(
		IRequest $request,
		IDBConnection $db,
		IRootFolder $rootFolder,
		DeleteService $deleteService,
		HiddenHashService $hiddenHashService,
		ScanService $scanService,
		?string $userId
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->db = $db;
		$this->rootFolder = $rootFolder;
		$this->deleteService = $deleteService;
		$this->hiddenHashService = $hiddenHashService;
		$this->scanService = $scanService;
		$this->userId = $userId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function index(): JSONResponse {
		$showHidden = $this->request->getParam('showHidden', '0') === '1';
		$limit = max(1, min(100, (int)$this->request->getParam('limit', '25')));
		$offset = max(0, (int)$this->request->getParam('offset', '0'));

		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$hiddenHashes = $this->hiddenHashService->getHiddenHashSet($this->userId);

		$qb = $this->db->getQueryBuilder();
		$pattern = '%' . $this->db->escapeLikeParameter('_' . $this->userId);
		$qb->select('id', 'hash', 'size', 'file_ids', 'protected_ids', 'scan_id', 'status')
			->from('cromcull_groups')
			->where($qb->expr()->like('scan_id', $qb->createNamedParameter($pattern)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));

		$result = $qb->executeQuery();
		$allRows = [];
		while ($row = $result->fetch()) {
			$isHidden = isset($hiddenHashes[$row['hash']]);
			if (!$showHidden && $isHidden) {
				continue;
			}
			$allRows[] = ['row' => $row, 'hidden' => $isHidden];
		}
		$result->closeCursor();

		$total = count($allRows);
		$pageRows = array_slice($allRows, $offset, $limit);

		$shareCache = [];
		$groups = [];
		foreach ($pageRows as $entry) {
			$row = $entry['row'];
			$fileIds = json_decode($row['file_ids'], true);

			$members = [];
			foreach ($fileIds as $fileId) {
				try {
					$nodes = $userFolder->getById($fileId);
					if (empty($nodes)) {
						continue;
					}
					$node = $nodes[0];
					$members[] = [
						'fileid' => $fileId,
						'path' => $userFolder->getRelativePath($node->getPath()),
						'name' => $node->getName(),
						'protected' => $this->scanService->isFileProtected($node, $this->userId, $userFolder, $shareCache),
					];
				} catch (\Exception $e) {
					continue;
				}
			}

			if (count($members) < 2) {
				$total--;
				continue;
			}

			$groups[] = [
				'id' => (int)$row['id'],
				'scan_id' => $row['scan_id'],
				'hash' => $row['hash'],
				'size' => (int)$row['size'],
				'status' => $row['status'],
				'hidden' => $entry['hidden'],
				'members' => $members,
			];
		}

		return new JSONResponse([
			'groups' => $groups,
			'total' => $total,
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function delete(int $id): JSONResponse {
		$selectedFileIds = $this->request->getParam('selectedFileIds', []);
		$displayedPaths = $this->request->getParam('displayedPaths', []);

		$selectedFileIds = array_map('intval', $selectedFileIds);

		$result = $this->deleteService->deleteGroupFiles(
			$this->userId,
			$id,
			$selectedFileIds,
			$displayedPaths
		);

		$httpStatus = 200;
		if ($result['status'] === 'error') {
			$httpStatus = 404;
		} elseif ($result['status'] === 'changed') {
			$httpStatus = 409;
		}

		return new JSONResponse($result, $httpStatus);
	}

	/**
	 * @NoAdminRequired
	 */
	public function hide(int $id): JSONResponse {
		$pattern = '%' . $this->db->escapeLikeParameter('_' . $this->userId);

		$qb = $this->db->getQueryBuilder();
		$qb->select('hash', 'size', 'file_ids')
			->from('cromcull_groups')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('scan_id', $qb->createNamedParameter($pattern)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (!$row) {
			return new JSONResponse(['error' => 'Group not found'], 404);
		}

		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$fileIds = json_decode($row['file_ids'], true);
		$examplePath = '';
		foreach ($fileIds as $fileId) {
			try {
				$nodes = $userFolder->getById($fileId);
				if (!empty($nodes)) {
					$examplePath = $userFolder->getRelativePath($nodes[0]->getPath());
					break;
				}
			} catch (\Exception $e) {
				continue;
			}
		}

		$this->hiddenHashService->hide(
			$this->userId,
			$row['hash'],
			$examplePath,
			(int)$row['size']
		);

		return new JSONResponse(['status' => 'hidden']);
	}

	/**
	 * @NoAdminRequired
	 */
	public function unhide(int $id): JSONResponse {
		$pattern = '%' . $this->db->escapeLikeParameter('_' . $this->userId);

		$qb = $this->db->getQueryBuilder();
		$qb->select('hash')
			->from('cromcull_groups')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('scan_id', $qb->createNamedParameter($pattern)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (!$row) {
			return new JSONResponse(['error' => 'Group not found'], 404);
		}

		$this->hiddenHashService->unhide($this->userId, $row['hash']);
		return new JSONResponse(['status' => 'unhidden']);
	}

	/**
	 * @NoAdminRequired
	 */
	public function recheck(int $id): JSONResponse {
		$result = $this->scanService->recheckGroup($this->userId, $id);

		if ($result['status'] === 'not_found') {
			return new JSONResponse(['error' => 'Group not found'], 404);
		}

		return new JSONResponse($result);
	}
}
