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
    ): BitrixOpenLinesSendMessageResult {
        $this->assertConfigured();

        $payloadMessage = [
            'user' => [
                'id' => $externalUserId,
            ],
            'chat' => [
                'id' => $externalThreadId,
                'name' => $contactDisplayName,
            ],
            'message' => [
                'id' => $sourceMessageId,
                'date' => $occurredAt->getTimestamp(),
                'text' => $body,
            ],
        ];

        $sanitizedName = $this->sanitizePersonName($contactDisplayName);
        if ($sanitizedName !== null) {
            $payloadMessage['user']['name'] = $sanitizedName;
        }

        $response = $this->restClient->call(
            $this->baseUrl,
            'imconnector.send.messages',
            $this->withAuth([
                'CONNECTOR' => $this->connectorId,
                'LINE' => $this->lineId,
                'MESSAGES' => [$payloadMessage],
            ]),
        );

        $this->assertMessageSent($response);
        $sessionId = $this->extractSessionId($response);
        $sessionChatId = $this->extractSessionChatId($response) ?? '';
        $externalMessageId = $this->extractMessageId($response) ?? $sourceMessageId;

        return new BitrixOpenLinesSendMessageResult(
            externalMessageId: $externalMessageId,
            sessionId: $sessionId ?? '',
            sessionChatId: $sessionChatId,
        );
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
                ['DATA', 'RESULT', 0, 'message', 'id'],
                ['data', 'result', 0, 'message', 'id'],
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
     * @param array<string, mixed> $response
     */
    private function assertMessageSent(array $response): void
    {
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            throw new RuntimeException('Bitrix response has no result payload.');
        }

        $overallSuccess = $result['SUCCESS'] ?? null;
        if ($overallSuccess === false || $overallSuccess === 'N' || $overallSuccess === 0 || $overallSuccess === '0') {
            $errors = $this->stringList($result['ERRORS'] ?? null);
            throw new RuntimeException(
                'Bitrix rejected message: ' . ($errors !== [] ? implode('; ', $errors) : 'unknown error'),
            );
        }

        $items = $result['DATA']['RESULT'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new RuntimeException('Bitrix response has no DATA.RESULT entries.');
        }

        $first = $items[0] ?? null;
        if (!is_array($first)) {
            return;
        }

        $itemSuccess = $first['SUCCESS'] ?? null;
        if ($itemSuccess === false || $itemSuccess === 'N' || $itemSuccess === 0 || $itemSuccess === '0') {
            $errors = $this->stringList($first['ERRORS'] ?? null);
            throw new RuntimeException(
                'Bitrix rejected message item: ' . ($errors !== [] ? implode('; ', $errors) : 'unknown error'),
            );
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractSessionId(array $response): ?string
    {
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }

        return $this->firstScalarFromPaths($result, [
            ['DATA', 'RESULT', 0, 'session', 'ID'],
            ['DATA', 'RESULT', 0, 'SESSION', 'ID'],
            ['data', 'result', 0, 'session', 'id'],
        ]);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractSessionChatId(array $response): ?string
    {
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }

        return $this->firstScalarFromPaths($result, [
            ['DATA', 'RESULT', 0, 'session', 'CHAT_ID'],
            ['DATA', 'RESULT', 0, 'SESSION', 'CHAT_ID'],
            ['data', 'result', 0, 'session', 'chat_id'],
        ]);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function responsePreview(array $response): string
    {
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            return 'result is not an object';
        }

        $preview = [
            'SUCCESS' => $result['SUCCESS'] ?? null,
            'ERRORS' => $result['ERRORS'] ?? null,
            'DATA.RESULT[0].SUCCESS' => $result['DATA']['RESULT'][0]['SUCCESS'] ?? null,
            'DATA.RESULT[0].ERRORS' => $result['DATA']['RESULT'][0]['ERRORS'] ?? null,
            'DATA.RESULT[0].session.ID' => $result['DATA']['RESULT'][0]['session']['ID'] ?? null,
            'DATA.RESULT[0].session.CHAT_ID' => $result['DATA']['RESULT'][0]['session']['CHAT_ID'] ?? null,
            'DATA.RESULT[0].message.id' => $result['DATA']['RESULT'][0]['message']['id'] ?? null,
        ];

        $encoded = json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return 'unable to encode preview';
        }

        return $encoded;
    }

    private function sanitizePersonName(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $clean = preg_replace("/[^\\p{L}\\s\\-']/u", '', $trimmed);
        if (!is_string($clean)) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        if ($clean === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            /** @var string $short */
            $short = mb_substr($clean, 0, 25);
            return $short;
        }

        return substr($clean, 0, 25);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return $result;
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
