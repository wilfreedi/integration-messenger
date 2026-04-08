<?php

declare(strict_types=1);

namespace ChatSync\Tests\Unit;

use ChatSync\App\Integration\Bitrix\BitrixOAuthClient;
use ChatSync\App\Integration\Bitrix\BitrixOAuthToken;
use ChatSync\App\Integration\Bitrix\BitrixPortalInstall;
use ChatSync\App\Integration\Bitrix\BitrixPortalInstallRepository;
use ChatSync\App\Integration\Bitrix\BitrixRoutingContext;
use ChatSync\App\Integration\Bitrix\RefreshingBitrixTokenManager;
use ChatSync\App\Integration\Bitrix\RegisterBitrixPortalInstallCommand;
use ChatSync\App\Integration\Bitrix\RegisterBitrixPortalInstallHandler;
use ChatSync\Shared\Domain\Clock;
use ChatSync\Shared\Domain\IdGenerator;
use ChatSync\Tests\Support\Assertions;
use DateTimeImmutable;

final class BitrixTokenRefreshResilienceTest
{
    public static function run(): void
    {
        self::itNormalizesAbsoluteUnixExpiresValueFromInstall();
        self::itRefreshesWhenStoredExpiryLooksUnrealisticallyFar();
    }

    private static function itNormalizesAbsoluteUnixExpiresValueFromInstall(): void
    {
        $clock = new FixedNowClock(new DateTimeImmutable('2026-04-08T11:00:00+00:00'));
        $repository = new InMemoryBitrixPortalInstallRepository();
        $handler = new RegisterBitrixPortalInstallHandler(
            $repository,
            new FixedIdGenerator(),
            $clock,
        );

        $absoluteExpiry = $clock->now()->getTimestamp() + 3600;
        $result = $handler(new RegisterBitrixPortalInstallCommand(
            portalDomain: 'portal.bitrix24.ru',
            memberId: 'member',
            accessToken: 'access',
            refreshToken: 'refresh',
            expiresInSeconds: $absoluteExpiry,
            scope: 'imconnector',
            applicationToken: 'app-token',
            restBaseUrl: 'https://portal.bitrix24.ru/rest',
            oauthClientId: 'local.1',
            oauthClientSecret: 'secret.1',
            oauthServerEndpoint: 'https://oauth.bitrix.info/rest',
        ));

        $stored = $repository->findByPortalDomain('portal.bitrix24.ru');
        Assertions::assertTrue($stored !== null, 'Install must be stored.');
        if ($stored === null) {
            return;
        }

        $delta = $stored->expiresAt->getTimestamp() - $clock->now()->getTimestamp();
        Assertions::assertTrue($delta >= 3500 && $delta <= 3700, 'Absolute expires must be converted to ~1 hour delta.');
        Assertions::assertSame($stored->expiresAt->format(DATE_ATOM), $result->expiresAt);
    }

    private static function itRefreshesWhenStoredExpiryLooksUnrealisticallyFar(): void
    {
        $clock = new FixedNowClock(new DateTimeImmutable('2026-04-08T12:00:00+00:00'));
        $repository = new InMemoryBitrixPortalInstallRepository();
        $oauthClient = new RecordingBitrixOAuthClient();
        $manager = new RefreshingBitrixTokenManager($oauthClient, $repository, $clock);

        $route = new BitrixRoutingContext(
            portalDomain: 'portal.bitrix24.ru',
            restBaseUrl: 'https://portal.bitrix24.ru/rest',
            connectorId: 'chat_sync',
            lineId: '192',
            accessToken: 'expired-but-marked-far',
            refreshToken: 'refresh-token',
            oauthClientId: 'local.1',
            oauthClientSecret: 'secret.1',
            oauthServerEndpoint: 'https://oauth.bitrix.info/rest',
            expiresAt: new DateTimeImmutable('2082-07-14T22:29:02+00:00'),
        );
        $repository->upsert(new BitrixPortalInstall(
            portalId: 'portal-1',
            installId: 'install-1',
            portalDomain: $route->portalDomain,
            memberId: 'member',
            appStatus: 'installed',
            accessToken: (string) $route->accessToken,
            refreshToken: (string) $route->refreshToken,
            expiresAt: $route->expiresAt,
            scope: 'imconnector',
            applicationToken: 'app-token',
            restBaseUrl: $route->restBaseUrl,
            oauthClientId: $route->oauthClientId,
            oauthClientSecret: $route->oauthClientSecret,
            oauthServerEndpoint: $route->oauthServerEndpoint,
            active: true,
            createdAt: $clock->now(),
            updatedAt: $clock->now(),
        ));

        $refreshed = $manager->ensureValidRoute($route, 'telegram-manager-account');

        Assertions::assertTrue($oauthClient->called, 'OAuth refresh must be called for unrealistic far expiry.');
        Assertions::assertSame('new-access-token', $refreshed->accessToken);
        Assertions::assertSame('new-refresh-token', $refreshed->refreshToken);
        Assertions::assertTrue($repository->updated, 'Repository tokens must be updated.');
    }
}

final class FixedNowClock implements Clock
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class FixedIdGenerator implements IdGenerator
{
    private int $seq = 0;

    public function next(): string
    {
        $this->seq++;
        return 'id-' . $this->seq;
    }
}

final class InMemoryBitrixPortalInstallRepository implements BitrixPortalInstallRepository
{
    /** @var array<string, BitrixPortalInstall> */
    private array $items = [];
    public bool $updated = false;

    public function upsert(BitrixPortalInstall $install): void
    {
        $this->items[strtolower($install->portalDomain)] = $install;
    }

    public function findByPortalDomain(string $portalDomain): ?BitrixPortalInstall
    {
        return $this->items[strtolower($portalDomain)] ?? null;
    }

    public function updateTokens(
        string $portalDomain,
        string $accessToken,
        string $refreshToken,
        DateTimeImmutable $expiresAt,
        string $scope
    ): void {
        $key = strtolower($portalDomain);
        $existing = $this->items[$key] ?? null;
        if ($existing === null) {
            return;
        }

        $this->items[$key] = new BitrixPortalInstall(
            portalId: $existing->portalId,
            installId: $existing->installId,
            portalDomain: $existing->portalDomain,
            memberId: $existing->memberId,
            appStatus: $existing->appStatus,
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresAt,
            scope: $scope,
            applicationToken: $existing->applicationToken,
            restBaseUrl: $existing->restBaseUrl,
            oauthClientId: $existing->oauthClientId,
            oauthClientSecret: $existing->oauthClientSecret,
            oauthServerEndpoint: $existing->oauthServerEndpoint,
            active: $existing->active,
            createdAt: $existing->createdAt,
            updatedAt: $existing->updatedAt,
        );
        $this->updated = true;
    }
}

final class RecordingBitrixOAuthClient implements BitrixOAuthClient
{
    public bool $called = false;

    public function refreshToken(
        string $tokenEndpoint,
        string $clientId,
        string $clientSecret,
        string $refreshToken
    ): BitrixOAuthToken {
        $this->called = true;

        return new BitrixOAuthToken(
            accessToken: 'new-access-token',
            refreshToken: 'new-refresh-token',
            expiresInSeconds: 3600,
            scope: 'imconnector',
        );
    }
}
