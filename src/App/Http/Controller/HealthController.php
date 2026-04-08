<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\Shared\Infrastructure\Config\AppConfig;

final readonly class HealthController
{
    public function __construct(private AppConfig $config)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return [
            'status' => 'ok',
            'app_env' => $this->config->appEnv,
            'bitrix_connector_mode' => $this->config->bitrixConnectorMode,
            'bitrix_management_api_protected' => $this->config->bitrixManagementToken !== '',
            'telegram_connector_mode' => $this->config->telegramConnectorMode,
        ];
    }
}
