<?php

declare(strict_types=1);

namespace ChatSync\Tests\Unit;

use ChatSync\App\Integration\Connector\GatewayTelegramChannelConnector;
use ChatSync\App\Integration\Connector\TelegramGatewayHttpClient;
use ChatSync\Core\Application\Dto\AttachmentData;
use ChatSync\Core\Application\Port\Connector\SendChannelMessageRequest;
use ChatSync\Shared\Infrastructure\Config\AppConfig;
use ChatSync\Tests\Support\Assertions;
use DateTimeImmutable;

final class GatewayTelegramChannelConnectorTest
{
    public static function run(): void
    {
        self::itMapsOutboundRequestToGatewayPayload();
    }

    private static function itMapsOutboundRequestToGatewayPayload(): void
    {
        $httpClient = new RecordingTelegramGatewayHttpClient();
        $connector = new GatewayTelegramChannelConnector(
            new AppConfig(
                appEnv: 'test',
                appPort: 8080,
                appDebug: true,
                bootstrapDemoData: false,
                siteDomain: '',
                dbHost: 'localhost',
                dbPort: 5432,
                dbName: 'chat_sync',
                dbUser: 'chat_sync',
                dbPassword: 'chat_sync',
                bitrixConnectorMode: 'stub',
                bitrixWebhookToken: '',
                bitrixManagementToken: '',
                bitrixDefaultChannelProvider: 'telegram',
                telegramConnectorMode: 'gateway',
                telegramGatewayBaseUrl: 'http://telegram-gateway:8090',
                telegramGatewayToken: 'secret-token',
            ),
            $httpClient,
        );

        $result = $connector->sendMessage(new SendChannelMessageRequest(
            managerAccountExternalId: 'telegram-manager-account',
            externalChatId: '123456',
            body: 'Reply to Telegram',
            occurredAt: new DateTimeImmutable('2026-04-07T13:00:00+00:00'),
            correlationId: 'corr-1',
            attachments: [
                new AttachmentData('document', 'file-1', 'contract.pdf', 'application/pdf'),
            ],
        ));

        Assertions::assertSame('telegram-message-99', $result->externalMessageId);
        Assertions::assertSame('http://telegram-gateway:8090', $httpClient->baseUrl);
        Assertions::assertSame('/v1/messages/send', $httpClient->path);
        Assertions::assertSame('secret-token', $httpClient->token);
        Assertions::assertSame('telegram-manager-account', $httpClient->payload['manager_account_external_id'] ?? null);
        Assertions::assertSame('123456', $httpClient->payload['external_chat_id'] ?? null);
        Assertions::assertSame('Reply to Telegram', $httpClient->payload['body'] ?? null);
        Assertions::assertSame('corr-1', $httpClient->payload['correlation_id'] ?? null);
        Assertions::assertCount(1, $httpClient->payload['attachments'] ?? []);
        Assertions::assertSame('contract.pdf', $httpClient->payload['attachments'][0]['file_name'] ?? null);
    }
}

final class RecordingTelegramGatewayHttpClient implements TelegramGatewayHttpClient
{
    public string $baseUrl = '';
    public string $path = '';
    public string $token = '';

    /** @var array<string, mixed> */
    public array $payload = [];

    public function post(string $baseUrl, string $path, string $token, array $payload): array
    {
        $this->baseUrl = $baseUrl;
        $this->path = $path;
        $this->token = $token;
        $this->payload = $payload;

        return [
            'status' => 'sent',
            'external_message_id' => 'telegram-message-99',
        ];
    }
}
