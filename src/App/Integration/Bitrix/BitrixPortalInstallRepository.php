<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

use DateTimeImmutable;

interface BitrixPortalInstallRepository
{
    public function upsert(BitrixPortalInstall $install): void;

    public function findByPortalDomain(string $portalDomain): ?BitrixPortalInstall;

    public function updateTokens(
        string $portalDomain,
        string $accessToken,
        string $refreshToken,
        DateTimeImmutable $expiresAt,
        string $scope
    ): void;
}
