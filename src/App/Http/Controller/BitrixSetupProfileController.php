<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\Shared\Infrastructure\Config\AppConfig;
use ChatSync\Shared\Infrastructure\Config\EnvFileStore;

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
        $env = (new EnvFileStore($this->envPath()))->read();

        $domain = $this->value($env, 'SITE_DOMAIN', $this->config->siteDomain);
        $baseUrl = $domain !== '' ? ('https://' . $domain) : '';
        $webhookToken = $this->value($env, 'BITRIX_WEBHOOK_TOKEN', $this->config->bitrixWebhookToken);
        $managementToken = $this->value($env, 'BITRIX_MANAGEMENT_TOKEN', $this->config->bitrixManagementToken);
        $acmeEmail = $this->value($env, 'ACME_EMAIL', '');
        $tokenQuery = $webhookToken !== '' ? ('?token=' . rawurlencode($webhookToken)) : '';

        return [
            'status' => 'ok',
            'site_domain' => $domain,
            'acme_email' => $acmeEmail,
            'public_base_url' => $baseUrl,
            'bitrix_connector_mode' => $this->config->bitrixConnectorMode,
            'bitrix_default_channel_provider' => $this->config->bitrixDefaultChannelProvider,
            'bitrix_webhook_token' => $webhookToken,
            'bitrix_management_token' => $managementToken,
            'bitrix_webhook_token_configured' => $webhookToken !== '',
            'bitrix_management_token_configured' => $managementToken !== '',
            'bitrix_app_handler_url' => $baseUrl !== '' ? ($baseUrl . '/bitrix/app' . $tokenQuery) : '',
            'bitrix_app_install_url' => $baseUrl !== '' ? ($baseUrl . '/bitrix/app' . $tokenQuery) : '',
            'bitrix_open_lines_webhook_url' => $baseUrl !== ''
                ? ($baseUrl . '/api/webhooks/bitrix/open-lines' . ($webhookToken !== '' ? ('?token=' . $webhookToken) : ''))
                : '',
            'bitrix_panel_url' => $baseUrl !== '' ? ($baseUrl . '/panel/bitrix') : '',
            'telegram_ui_url' => $baseUrl !== '' ? ($baseUrl . '/telegram/') : '',
        ];
    }

    /**
     * @param array<string, string> $env
     */
    private function value(array $env, string $key, string $fallback): string
    {
        $value = $env[$key] ?? '';

        return $value !== '' ? $value : $fallback;
    }

    private function envPath(): string
    {
        return dirname(__DIR__, 4) . '/.env';
    }
}
