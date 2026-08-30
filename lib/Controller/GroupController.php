<?php

declare(strict_types=1);

namespace OCA\CromCull\Controller;

use OCA\CromCull\AppInfo\Application;
use OCA\CromCull\Service\DeleteService;
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
	private ?string $userId;

	public function __construct(
		IRequest $request,
		IDBConnection $db,
		IRootFolder $rootFolder,
		DeleteService $deleteService,
		?string $userId
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->db = $db;
		$this->rootFolder = $rootFolder;
		$this->deleteService = $deleteService;
		$this->userId = $userId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function index(): JSONResponse {
		$userFolder = $this->rootFolder->getUserFolder($this->userId);

		$qb = $this->db->getQueryBuilder();
		$pattern = '%' . $this->db->escapeLikeParameter('_' . $this->userId);
		$qb->select('*')
			->from('cromcull_groups')
			->where($qb->expr()->like('scan_id', $qb->createNamedParameter($pattern)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));

		$result = $qb->executeQuery();
		$groups = [];

		while ($row = $result->fetch()) {
			$fileIds = json_decode($row['file_ids'], true);
			$protectedIds = json_decode($row['protected_ids'], true) ?: [];

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
						'protected' => in_array($fileId, $protectedIds),
					];
				} catch (\Exception $e) {
					continue;
				}
			}

			if (count($members) < 2) {
				continue;
			}

			$groups[] = [
				'id' => (int)$row['id'],
				'scan_id' => $row['scan_id'],
				'hash' => $row['hash'],
				'size' => (int)$row['size'],
				'status' => $row['status'],
				'members' => $members,
			];
		}
		$result->closeCursor();

		return new JSONResponse($groups);
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
	public function dismiss(int $id): JSONResponse {
		$pattern = '%' . $this->db->escapeLikeParameter('_' . $this->userId);

		$qb = $this->db->getQueryBuilder();
		$qb->update('cromcull_groups')
			->set('status', $qb->createNamedParameter('dismissed'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('scan_id', $qb->createNamedParameter($pattern)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		$affected = $qb->executeStatement();

		if ($affected === 0) {
			return new JSONResponse(['error' => 'Group not found or not pending'], 404);
		}

		return new JSONResponse(['status' => 'dismissed']);
	}
}
