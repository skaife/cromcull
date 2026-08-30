<?php

declare(strict_types=1);

namespace OCA\CromCull\Service;

use OCA\CromCull\AppInfo\Application;
use OCA\CromCull\Notification\Notifier;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;

class IgnoreBaselineService {
	private IRootFolder $rootFolder;
	private IConfig $config;
	private INotificationManager $notificationManager;
	private IGroupManager $groupManager;

	public function __construct(
		IRootFolder $rootFolder,
		IConfig $config,
		INotificationManager $notificationManager,
		IGroupManager $groupManager
	) {
		$this->rootFolder = $rootFolder;
		$this->config = $config;
		$this->notificationManager = $notificationManager;
		$this->groupManager = $groupManager;
	}

	public function checkAndUpdateBaseline(string $userId): array {
		$currentView = $this->getCurrentView($userId);
		$baseline = $this->getBaseline($userId);

		if ($baseline === null) {
			$this->setBaseline($userId, $currentView);
			return ['added' => 0, 'removed' => 0];
		}

		$currentIds = array_keys($currentView);
		$baselineIds = array_keys($baseline);

		$addedIds = array_diff($currentIds, $baselineIds);
		$removedIds = array_diff($baselineIds, $currentIds);

		if (empty($addedIds) && empty($removedIds)) {
			return ['added' => 0, 'removed' => 0];
		}

		$this->setBaseline($userId, $currentView);

		$addedFolders = [];
		$userLevelAdded = [];
		foreach ($addedIds as $id) {
			$folder = ['folder_fileid' => $id, 'path' => $currentView[$id]['path']];
			$addedFolders[] = $folder;
			if (!$currentView[$id]['admin']) {
				$userLevelAdded[] = $folder;
			}
		}

		$removedFolders = [];
		$userLevelRemoved = [];
		foreach ($removedIds as $id) {
			$folder = ['folder_fileid' => $id, 'path' => $baseline[$id]['path']];
			$removedFolders[] = $folder;
			if (!$baseline[$id]['admin']) {
				$userLevelRemoved[] = $folder;
			}
		}

		if (!empty($addedFolders)) {
			$this->sendNotification($userId, Notifier::SUBJECT_MARKER_ADDED, $addedFolders);
		}
		if (!empty($removedFolders)) {
			$this->sendNotification($userId, Notifier::SUBJECT_MARKER_REMOVED, $removedFolders);
		}

		if (!empty($userLevelAdded) || !empty($userLevelRemoved)) {
			$this->notifyAdmins($userId, $userLevelAdded, $userLevelRemoved);
		}

		return [
			'added' => count($addedIds),
			'removed' => count($removedIds),
		];
	}

	public function updateBaseline(string $userId): void {
		$currentView = $this->getCurrentView($userId);
		$this->setBaseline($userId, $currentView);
	}

	private function getCurrentView(string $userId): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$isAdmin = $this->groupManager->isAdmin($userId);

		$nodes = $userFolder->search('.cromcull_ignore');
		$view = [];

		foreach ($nodes as $node) {
			if (!($node instanceof File) || $node->getName() !== '.cromcull_ignore') {
				continue;
			}

			try {
				$config = CromcullIgnoreParser::parse($node->getContent());
			} catch (\Exception $e) {
				continue;
			}

			if (!$config['valid']) {
				continue;
			}

			if ($isAdmin || $config['admin_excluded'] || in_array($userId, $config['users'])) {
				$parent = $node->getParent();
				$userPath = $userFolder->getRelativePath($parent->getPath());
				if ($userPath !== null && $userPath !== '' && $userPath !== '/') {
					$view[(string)$parent->getId()] = [
						'path' => $userPath,
						'admin' => $config['admin_excluded'],
					];
				}
			}
		}

		return $view;
	}

	private function getBaseline(string $userId): ?array {
		$raw = $this->config->getUserValue($userId, Application::APP_ID, 'ignore_baseline', '');
		if ($raw === '') {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	private function setBaseline(string $userId, array $view): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'ignore_baseline', json_encode($view));
	}

	private function notifyAdmins(string $triggeringUserId, array $added, array $removed): void {
		$adminGroup = $this->groupManager->get('admin');
		if (!$adminGroup) {
			return;
		}

		foreach ($adminGroup->getUsers() as $admin) {
			$adminId = $admin->getUID();
			if ($adminId === $triggeringUserId) {
				continue;
			}

			if (!empty($added)) {
				$this->sendNotification($adminId, Notifier::SUBJECT_MARKER_ADDED, $added, $triggeringUserId);
			}
			if (!empty($removed)) {
				$this->sendNotification($adminId, Notifier::SUBJECT_MARKER_REMOVED, $removed, $triggeringUserId);
			}
		}
	}

	private function sendNotification(string $userId, string $subject, array $items, ?string $sourceUser = null): void {
		$paths = array_map(fn($item) => $item['path'], $items);

		$params = [
			'count' => count($items),
			'paths' => $paths,
		];
		if ($sourceUser !== null) {
			$params['source_user'] = $sourceUser;
		}

		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('baseline', (string)time())
			->setSubject($subject, $params);

		$this->notificationManager->notify($notification);
	}
}
