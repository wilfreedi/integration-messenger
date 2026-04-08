<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\Shared\Infrastructure\Config\AppConfig;

final readonly class BitrixSetupProfileController
{
    public function __construct(private AppConfig $config)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $domain = $this->config->siteDomain;
        $baseUrl = $domain !== '' ? ('https://' . $domain) : '';
        $webhookToken = $this->config->bitrixWebhookToken;
        $managementToken = $this->config->bitrixManagementToken;

        return [
            'status' => 'ok',
            'site_domain' => $domain,
            'public_base_url' => $baseUrl,
            'bitrix_connector_mode' => $this->config->bitrixConnectorMode,
            'bitrix_default_channel_provider' => $this->config->bitrixDefaultChannelProvider,
            'bitrix_webhook_token' => $webhookToken,
            'bitrix_management_token' => $managementToken,
            'bitrix_webhook_token_configured' => $webhookToken !== '',
            'bitrix_management_token_configured' => $managementToken !== '',
            'bitrix_app_handler_url' => $baseUrl !== '' ? ($baseUrl . '/bitrix/app') : '',
            'bitrix_app_install_url' => $baseUrl !== '' ? ($baseUrl . '/bitrix/app') : '',
            'bitrix_open_lines_webhook_url' => $baseUrl !== ''
                ? ($baseUrl . '/api/webhooks/bitrix/open-lines' . ($webhookToken !== '' ? ('?token=' . $webhookToken) : ''))
                : '',
            'bitrix_panel_url' => $baseUrl !== '' ? ($baseUrl . '/panel/bitrix') : '',
            'telegram_ui_url' => $baseUrl !== '' ? ($baseUrl . '/telegram/') : '',
        ];
    }
}
