<?php

declare(strict_types=1);

namespace OCA\CromCull\Notification;

use OCA\CromCull\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public const SUBJECT_MARKER_REMOVED = 'marker_removed';
	public const SUBJECT_MARKER_ADDED = 'marker_added';

	private IFactory $l10nFactory;
	private IURLGenerator $urlGenerator;

	public function __construct(IFactory $l10nFactory, IURLGenerator $urlGenerator) {
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('CromCull');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException('Not CromCull');
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();

		switch ($notification->getSubject()) {
			case self::SUBJECT_MARKER_REMOVED:
				$count = (int)($params['count'] ?? 1);
				$paths = $params['paths'] ?? [];
				$sourceUser = $params['source_user'] ?? null;
				if ($sourceUser) {
					if ($count === 1 && !empty($paths)) {
						$notification->setParsedSubject(
							$l->t('.cromcull_ignore removed from %1$s (user %2$s) — folder no longer excluded', [$paths[0], $sourceUser])
						);
					} else {
						$notification->setParsedSubject(
							$l->t('.cromcull_ignore removed from %1$s folders (user %2$s) — no longer excluded', [(string)$count, $sourceUser])
						);
					}
				} else {
					if ($count === 1 && !empty($paths)) {
						$notification->setParsedSubject(
							$l->t('.cromcull_ignore removed from %s — folder is no longer excluded from scans', [$paths[0]])
						);
					} else {
						$notification->setParsedSubject(
							$l->t('.cromcull_ignore removed from %n folder — no longer excluded from scans', [$count])
						);
					}
				}
				$notification->setIcon($this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'));
				return $notification;

			case self::SUBJECT_MARKER_ADDED:
				$count = (int)($params['count'] ?? 1);
				$paths = $params['paths'] ?? [];
				$sourceUser = $params['source_user'] ?? null;
				if ($sourceUser) {
					if ($count === 1 && !empty($paths)) {
						$notification->setParsedSubject(
							$l->t('.cromcull_ignore added to %1$s (user %2$s) — folder excluded from scans', [$paths[0], $sourceUser])
						);
					} else {
						$notification->setParsedSubject(
							$l->t('.cromcull_ignore added to %1$s folders (user %2$s) — excluded from scans', [(string)$count, $sourceUser])
						);
					}
				} else {
					if ($count === 1 && !empty($paths)) {
						$notification->setParsedSubject(
							$l->t('.cromcull_ignore added to %s — folder will be excluded from scans', [$paths[0]])
						);
					} else {
						$notification->setParsedSubject(
							$l->t('.cromcull_ignore added to %n folder — will be excluded from scans', [$count])
						);
					}
				}
				$notification->setIcon($this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'));
				return $notification;

			default:
				throw new UnknownNotificationException('Unknown subject');
		}
	}
}
