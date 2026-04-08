<?php

declare(strict_types=1);

namespace ChatSync\Tests\Unit;

use ChatSync\App\Integration\Connector\BitrixOpenLinesApi;
use ChatSync\App\Integration\Connector\BitrixRestClient;
use ChatSync\Tests\Support\Assertions;
use DateTimeImmutable;

final class BitrixOpenLinesApiTest
{
    public static function run(): void
    {
        self::itSendsMessagesAndParsesBitrixMessageId();
        self::itSendsDeliveryStatus();
    }

    private static function itSendsMessagesAndParsesBitrixMessageId(): void
    {
        $restClient = new RecordingBitrixRestClient([
            'result' => [
                'SUCCESS' => true,
                'DATA' => [
                    'RESULT' => [
                        [
                            'SUCCESS' => true,
                            'message' => ['id' => 'bitrix-message-100'],
                            'session' => ['ID' => '323', 'CHAT_ID' => '1767'],
                        ],
                    ],
                ],
            ],
        ]);

        $api = new BitrixOpenLinesApi(
            baseUrl: 'https://example.bitrix24.ru/rest/1/secret',
            connectorId: 'chat_sync',
            lineId: '12',
            restClient: $restClient,
        );

        $externalMessageId = $api->sendMessage(
            externalThreadId: 'conversation-1',
            externalUserId: 'telegram-user-1',
            contactDisplayName: 'Alice Example',
            body: 'hello',
            occurredAt: new DateTimeImmutable('2026-04-07T10:00:00+00:00'),
            sourceMessageId: 'source-1',
        );

        Assertions::assertSame('bitrix-message-100', $externalMessageId);
        Assertions::assertSame('imconnector.send.messages', $restClient->lastMethod);
        Assertions::assertSame('conversation-1', $restClient->lastPayload['MESSAGES'][0]['chat']['id'] ?? null);
        Assertions::assertSame('telegram-user-1', $restClient->lastPayload['MESSAGES'][0]['user']['id'] ?? null);
        Assertions::assertSame('source-1', $restClient->lastPayload['MESSAGES'][0]['message']['id'] ?? null);
    }

    private static function itSendsDeliveryStatus(): void
    {
        $restClient = new RecordingBitrixRestClient(['result' => true]);
        $api = new BitrixOpenLinesApi(
            baseUrl: 'https://example.bitrix24.ru/rest/1/secret',
            connectorId: 'chat_sync',
            lineId: '12',
            restClient: $restClient,
        );

        $api->sendDeliveryStatus('im-9001', 'chat-700', ['telegram-message-1']);

        Assertions::assertSame('imconnector.send.status.delivery', $restClient->lastMethod);
        Assertions::assertSame('chat-700', $restClient->lastPayload['MESSAGES'][0]['im']['chat_id'] ?? null);
        Assertions::assertSame('im-9001', $restClient->lastPayload['MESSAGES'][0]['im']['message_id'] ?? null);
        Assertions::assertSame('telegram-message-1', $restClient->lastPayload['MESSAGES'][0]['message']['id'][0] ?? null);
    }
}

final class RecordingBitrixRestClient implements BitrixRestClient
{
    public string $lastBaseUrl = '';
    public string $lastMethod = '';

    /** @var array<string, mixed> */
    public array $lastPayload = [];

    /**
     * @param array<string, mixed> $response
     */
    public function __construct(private readonly array $response)
    {
    }

    public function call(string $baseUrl, string $method, array $payload): array
    {
        $this->lastBaseUrl = $baseUrl;
        $this->lastMethod = $method;
        $this->lastPayload = $payload;

        return $this->response;
    }
}
