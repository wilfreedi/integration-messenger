<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

use DateTimeImmutable;
use RuntimeException;

final readonly class BitrixOpenLinesApi
{
    public function __construct(
        private string $baseUrl,
        private string $connectorId,
        private string $lineId,
        private BitrixRestClient $restClient,
        private ?string $authToken = null,
    ) {
    }

    public function sendMessage(
        string $externalThreadId,
        string $externalUserId,
        string $contactDisplayName,
        string $body,
        DateTimeImmutable $occurredAt,
        string $sourceMessageId,
    ): string {
        $this->assertConfigured();

        $payloadMessage = [
            'user' => [
                'id' => $externalUserId,
                'name' => $contactDisplayName,
            ],
            'chat' => [
                'id' => $externalThreadId,
                'name' => $contactDisplayName,
            ],
            'message' => [
                'id' => $sourceMessageId,
                'date' => $occurredAt->format(DATE_ATOM),
                'text' => $body,
            ],
        ];

        $response = $this->restClient->call(
            $this->baseUrl,
            'imconnector.send.messages',
            $this->withAuth([
                'CONNECTOR' => $this->connectorId,
                'LINE' => $this->lineId,
                // Some Bitrix installations use DATA, others MESSAGES in REST docs/examples.
                'MESSAGES' => [$payloadMessage],
                'DATA' => [$payloadMessage],
            ]),
        );

        return $this->extractMessageId($response) ?? ('bitrix-message-' . bin2hex(random_bytes(8)));
    }

    /**
     * @param list<string> $externalMessageIds
     */
    public function sendDeliveryStatus(string $imMessageId, string $imChatId, array $externalMessageIds): void
    {
        $this->assertConfigured();

        if ($externalMessageIds === []) {
            return;
        }

        $payloadMessage = [
            'im' => [
                'chat_id' => $imChatId,
                'message_id' => $imMessageId,
            ],
            'message' => [
                'id' => array_values($externalMessageIds),
            ],
        ];

        $this->restClient->call(
            $this->baseUrl,
            'imconnector.send.status.delivery',
            $this->withAuth([
                'CONNECTOR' => $this->connectorId,
                'LINE' => $this->lineId,
                'MESSAGES' => [$payloadMessage],
                'DATA' => [$payloadMessage],
            ]),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withAuth(array $payload): array
    {
        if ($this->authToken === null || $this->authToken === '') {
            return $payload;
        }

        $payload['auth'] = $this->authToken;

        return $payload;
    }

    private function assertConfigured(): void
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('Bitrix route base URL must be configured.');
        }

        if ($this->connectorId === '') {
            throw new RuntimeException('Bitrix route connector ID must be configured.');
        }

        if ($this->lineId === '') {
            throw new RuntimeException('Bitrix route line ID must be configured.');
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractMessageId(array $response): ?string
    {
        $result = $response['result'] ?? null;

        if (is_array($result)) {
            $direct = $this->firstScalarFromPaths($result, [
                ['message', 'id'],
                ['MESSAGE', 'ID'],
                ['messages', 0, 'id'],
                ['MESSAGES', 0, 'ID'],
                ['data', 0, 'message', 'id'],
                ['DATA', 0, 'MESSAGE', 'ID'],
                ['id'],
                ['ID'],
            ]);

            if ($direct !== null) {
                return $direct;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $root
     * @param list<list<int|string>> $paths
     */
    private function firstScalarFromPaths(array $root, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->valueByPath($root, $path);
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $root
     * @param list<int|string> $path
     */
    private function valueByPath(array $root, array $path): mixed
    {
        $value = $root;
        foreach ($path as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }
}
