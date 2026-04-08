<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

use ChatSync\Core\Application\Port\Connector\ChannelConnector;
use ChatSync\Core\Application\Port\Connector\SendChannelMessageRequest;
use ChatSync\Core\Application\Port\Connector\SendChannelMessageResult;
use ChatSync\Shared\Infrastructure\Config\AppConfig;
use RuntimeException;

final readonly class GatewayTelegramChannelConnector implements ChannelConnector
{
    public function __construct(
        private AppConfig $config,
        private TelegramGatewayHttpClient $httpClient,
    ) {
    }

    public function sendMessage(SendChannelMessageRequest $request): SendChannelMessageResult
    {
        $payload = [
            'manager_account_external_id' => $request->managerAccountExternalId,
            'external_chat_id' => $request->externalChatId,
            'body' => $request->body,
            'occurred_at' => $request->occurredAt->format(DATE_ATOM),
            'correlation_id' => $request->correlationId,
            'attachments' => array_map(
                static fn (object $attachment): array => [
                    'type' => $attachment->type,
                    'external_file_id' => $attachment->externalFileId,
                    'file_name' => $attachment->fileName,
                    'mime_type' => $attachment->mimeType,
                ],
                $request->attachments,
            ),
        ];

        $decoded = $this->httpClient->post(
            $this->config->telegramGatewayBaseUrl,
            '/v1/messages/send',
            $this->config->telegramGatewayToken,
            $payload,
        );
        if (!is_array($decoded) || !isset($decoded['external_message_id']) || !is_string($decoded['external_message_id'])) {
            throw new RuntimeException('Telegram gateway response does not contain external_message_id.');
        }

        return new SendChannelMessageResult($decoded['external_message_id']);
    }
}
