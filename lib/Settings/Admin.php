<?php

declare(strict_types=1);

namespace OCA\CromCull\Settings;

use OCA\CromCull\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	private IConfig $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	public function getForm(): TemplateResponse {
		$params = [
			'min_size' => $this->config->getAppValue(Application::APP_ID, 'min_size', '0'),
			'max_size' => $this->config->getAppValue(Application::APP_ID, 'max_size', '0'),
			'ignored_extensions' => $this->config->getAppValue(Application::APP_ID, 'ignored_extensions', ''),
			'chunk_budget' => $this->config->getAppValue(Application::APP_ID, 'chunk_budget', '104857600'),
		];
		return new TemplateResponse(Application::APP_ID, 'admin', $params);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
