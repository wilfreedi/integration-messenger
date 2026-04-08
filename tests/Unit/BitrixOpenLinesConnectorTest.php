<?php

declare(strict_types=1);

namespace ChatSync\Tests\Unit;

use ChatSync\App\Integration\Bitrix\BitrixRoutingContext;
use ChatSync\App\Integration\Bitrix\BitrixRoutingResolver;
use ChatSync\App\Integration\Bitrix\BitrixTokenManager;
use ChatSync\App\Integration\Connector\BitrixOpenLinesConnectorLifecycle;
use ChatSync\App\Integration\Connector\BitrixOpenLinesConnector;
use ChatSync\App\Integration\Connector\BitrixRestClient;
use ChatSync\Core\Application\Port\Connector\SendCrmMessageRequest;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Tests\Support\Assertions;
use DateTimeImmutable;
use RuntimeException;

final class BitrixOpenLinesConnectorTest
{
    public static function run(): void
    {
        self::itFailsWhenRoutingBindingIsMissing();
        self::itUsesRoutingContextForBoundManagerAccount();
        self::itUsesTokenManagerForExpiredRoute();
    }

    private static function itFailsWhenRoutingBindingIsMissing(): void
    {
        $routingClient = new ConnectorRecordingBitrixRestClient([
            'result' => [
                'SUCCESS' => true,
                'DATA' => [
                    'RESULT' => [
                        [
                            'SUCCESS' => true,
                            'message' => ['id' => 'bitrix-routing-message-1'],
                            'session' => ['ID' => '1', 'CHAT_ID' => '10'],
                        ],
                    ],
                ],
            ],
        ]);

        $connector = new BitrixOpenLinesConnector(
            $routingClient,
            new StaticBitrixRoutingResolver(null),
            new StaticBitrixTokenManager(null),
            new NoopBitrixLifecycle(),
        );

        $thrown = false;
        try {
            $connector->sendMessage(self::request('telegram-manager-account'));
        } catch (RuntimeException $exception) {
            $thrown = true;
            Assertions::assertTrue(str_contains($exception->getMessage(), 'binding'));
        }

        Assertions::assertTrue($thrown, 'Expected runtime error when manager binding is missing.');
        Assertions::assertSame('', $routingClient->lastMethod, 'Bitrix request must not be sent without binding.');
    }

    private static function itUsesRoutingContextForBoundManagerAccount(): void
    {
        $routingClient = new ConnectorRecordingBitrixRestClient([
            'result' => [
                'SUCCESS' => true,
                'DATA' => [
                    'RESULT' => [
                        [
                            'SUCCESS' => true,
                            'message' => ['id' => 'bitrix-routing-message-2'],
                            'session' => ['ID' => '2', 'CHAT_ID' => '20'],
                        ],
                    ],
                ],
            ],
        ]);

        $resolver = new StaticBitrixRoutingResolver(new BitrixRoutingContext(
            portalDomain: 'portal-a.bitrix24.ru',
            restBaseUrl: 'https://portal-a.bitrix24.ru/rest',
            connectorId: 'chat_sync_a',
            lineId: '77',
            accessToken: 'oauth-access-token',
            refreshToken: 'refresh-token',
            oauthClientId: 'client-id',
            oauthClientSecret: 'client-secret',
            oauthServerEndpoint: 'https://oauth.bitrix.info/rest',
            expiresAt: new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
        ));

        $connector = new BitrixOpenLinesConnector(
            $routingClient,
            $resolver,
            new StaticBitrixTokenManager(null),
            new NoopBitrixLifecycle(),
        );

        $result = $connector->sendMessage(self::request('telegram-manager-account'));

        Assertions::assertSame('bitrix-routing-message-2', $result->externalMessageId);
        Assertions::assertSame('https://portal-a.bitrix24.ru/rest', $routingClient->lastBaseUrl);
        Assertions::assertSame('imconnector.send.messages', $routingClient->lastMethod);
        Assertions::assertSame('chat_sync_a', $routingClient->lastPayload['CONNECTOR'] ?? null);
        Assertions::assertSame('77', $routingClient->lastPayload['LINE'] ?? null);
        Assertions::assertSame('oauth-access-token', $routingClient->lastPayload['auth'] ?? null);
        Assertions::assertSame('telegram-user-7', $routingClient->lastPayload['MESSAGES'][0]['chat']['id'] ?? null);
        Assertions::assertSame('telegram-user-7', $routingClient->lastPayload['MESSAGES'][0]['user']['id'] ?? null);
    }

    private static function request(string $managerAccountExternalId): SendCrmMessageRequest
    {
        return new SendCrmMessageRequest(
            externalThreadId: 'conversation-42',
            channelProvider: ChannelProvider::TELEGRAM,
            managerAccountExternalId: $managerAccountExternalId,
            contactDisplayName: 'Alice Example',
            externalContactUserId: 'telegram-user-7',
            body: 'Hello from connector test',
            occurredAt: new DateTimeImmutable('2026-04-07T10:00:00+00:00'),
            correlationId: 'corr-42',
            attachments: [],
        );
    }

    private static function itUsesTokenManagerForExpiredRoute(): void
    {
        $routingClient = new ConnectorRecordingBitrixRestClient([
            'result' => [
                'SUCCESS' => true,
                'DATA' => [
                    'RESULT' => [
                        [
                            'SUCCESS' => true,
                            'message' => ['id' => 'bitrix-routing-message-3'],
                            'session' => ['ID' => '3', 'CHAT_ID' => '30'],
                        ],
                    ],
                ],
            ],
        ]);

        $expiredRoute = new BitrixRoutingContext(
            portalDomain: 'portal-expired.bitrix24.ru',
            restBaseUrl: 'https://portal-expired.bitrix24.ru/rest',
            connectorId: 'chat_sync_expired',
            lineId: '99',
            accessToken: 'expired-token',
            refreshToken: 'refresh-token',
            oauthClientId: 'client-id',
            oauthClientSecret: 'client-secret',
            oauthServerEndpoint: 'https://oauth.bitrix.info/rest',
            expiresAt: new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
        );

        $resolver = new StaticBitrixRoutingResolver($expiredRoute);

        $connector = new BitrixOpenLinesConnector(
            $routingClient,
            $resolver,
            new StaticBitrixTokenManager(new BitrixRoutingContext(
                portalDomain: 'portal-expired.bitrix24.ru',
                restBaseUrl: 'https://portal-expired.bitrix24.ru/rest',
                connectorId: 'chat_sync_expired',
                lineId: '99',
                accessToken: 'fresh-token',
                refreshToken: 'new-refresh-token',
                oauthClientId: 'client-id',
                oauthClientSecret: 'client-secret',
                oauthServerEndpoint: 'https://oauth.bitrix.info/rest',
                expiresAt: new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
            )),
            new NoopBitrixLifecycle(),
        );

        $result = $connector->sendMessage(self::request('telegram-manager-account'));

        Assertions::assertSame('bitrix-routing-message-3', $result->externalMessageId);
        Assertions::assertSame('fresh-token', $routingClient->lastPayload['auth'] ?? null);
        Assertions::assertSame('telegram-user-7', $routingClient->lastPayload['MESSAGES'][0]['chat']['id'] ?? null);
    }
}

final class NoopBitrixLifecycle implements BitrixOpenLinesConnectorLifecycle
{
    public function ensure(string $baseUrl, string $connectorId, string $lineId, ?string $authToken): void
    {
    }
}

final class StaticBitrixRoutingResolver implements BitrixRoutingResolver
{
    public function __construct(private readonly ?BitrixRoutingContext $context)
    {
    }

    public function resolveForManagerAccount(string $channelProvider, string $managerAccountExternalId): ?BitrixRoutingContext
    {
        return $this->context;
    }
}

final class StaticBitrixTokenManager implements BitrixTokenManager
{
    public function __construct(private readonly ?BitrixRoutingContext $replacement)
    {
    }

    public function ensureValidRoute(BitrixRoutingContext $route, string $managerAccountExternalId): BitrixRoutingContext
    {
        if ($this->replacement !== null) {
            return $this->replacement;
        }

        return $route;
    }
}

final class ConnectorRecordingBitrixRestClient implements BitrixRestClient
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
