<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\Shared\Infrastructure\Config\EnvFileStore;

final readonly class BitrixSetupGenerateTokensController
{
    public function handle(): array
    {
        $envFile = new EnvFileStore($this->envPath());
        $env = $envFile->read();

        $webhookToken = trim((string) ($env['BITRIX_WEBHOOK_TOKEN'] ?? ''));
        $managementToken = trim((string) ($env['BITRIX_MANAGEMENT_TOKEN'] ?? ''));

        $generatedWebhook = false;
        $generatedManagement = false;

        if ($webhookToken === '') {
            $webhookToken = bin2hex(random_bytes(32));
            $generatedWebhook = true;
        }
        if ($managementToken === '') {
            $managementToken = bin2hex(random_bytes(32));
            $generatedManagement = true;
        }

        if ($generatedWebhook || $generatedManagement) {
            $envFile->upsert([
                'BITRIX_WEBHOOK_TOKEN' => $webhookToken,
                'BITRIX_MANAGEMENT_TOKEN' => $managementToken,
            ]);
        }

        return [
            'status' => 'ok',
            'bitrix_webhook_token' => $webhookToken,
            'bitrix_management_token' => $managementToken,
            'generated_webhook_token' => $generatedWebhook,
            'generated_management_token' => $generatedManagement,
            'generated_count' => ($generatedWebhook ? 1 : 0) + ($generatedManagement ? 1 : 0),
            'env_updated' => $generatedWebhook || $generatedManagement,
            'restart_required' => $generatedWebhook || $generatedManagement,
        ];
    }

    private function envPath(): string
    {
        return dirname(__DIR__, 4) . '/.env';
    }
}
