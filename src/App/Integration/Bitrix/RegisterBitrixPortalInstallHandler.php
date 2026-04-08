<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

use ChatSync\Shared\Domain\Clock;
use ChatSync\Shared\Domain\IdGenerator;

final readonly class RegisterBitrixPortalInstallHandler
{
    public function __construct(
        private BitrixPortalInstallRepository $repository,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function __invoke(RegisterBitrixPortalInstallCommand $command): RegisterBitrixPortalInstallResult
    {
        $now = $this->clock->now();
        $existing = $this->repository->findByPortalDomain($command->portalDomain);
        $portalId = $existing?->portalId ?? $this->idGenerator->next();
        $installId = $existing?->installId ?? $this->idGenerator->next();
        $createdAt = $existing?->createdAt ?? $now;
        $expiresInSeconds = $this->normalizeExpiresInSeconds($command->expiresInSeconds, $now->getTimestamp());
        $expiresAt = $now->modify(sprintf('+%d seconds', $expiresInSeconds));
        $oauthClientId = $command->oauthClientId ?? $existing?->oauthClientId;
        $oauthClientSecret = $command->oauthClientSecret ?? $existing?->oauthClientSecret;
        $oauthServerEndpoint = $command->oauthServerEndpoint ?? $existing?->oauthServerEndpoint;

        $install = new BitrixPortalInstall(
            portalId: $portalId,
            installId: $installId,
            portalDomain: $command->portalDomain,
            memberId: $command->memberId,
            appStatus: 'installed',
            accessToken: $command->accessToken,
            refreshToken: $command->refreshToken,
            expiresAt: $expiresAt,
            scope: $command->scope,
            applicationToken: $command->applicationToken,
            restBaseUrl: $command->restBaseUrl,
            oauthClientId: $oauthClientId,
            oauthClientSecret: $oauthClientSecret,
            oauthServerEndpoint: $oauthServerEndpoint,
            active: true,
            createdAt: $createdAt,
            updatedAt: $now,
        );

        $this->repository->upsert($install);

        return new RegisterBitrixPortalInstallResult(
            portalDomain: $install->portalDomain,
            portalId: $install->portalId,
            installId: $install->installId,
            expiresAt: $install->expiresAt->format(DATE_ATOM),
        );
    }

    private function normalizeExpiresInSeconds(int $rawValue, int $nowTimestamp): int
    {
        $value = max(1, $rawValue);

        // Bitrix may send absolute unix timestamp in AUTH_EXPIRES/expires.
        if ($value > 1_000_000_000) {
            $delta = $value - $nowTimestamp;
            if ($delta > 0 && $delta <= 86400) {
                return max(60, $delta);
            }
        }

        // Access tokens in Bitrix are short-lived; clamp unrealistic values.
        if ($value > 86400) {
            return 3600;
        }

        return $value;
    }
}
