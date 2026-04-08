<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

final readonly class RegisterBitrixPortalInstallCommand
{
    public function __construct(
        public string $portalDomain,
        public ?string $memberId,
        public string $accessToken,
        public string $refreshToken,
        public int $expiresInSeconds,
        public string $scope,
        public string $applicationToken,
        public string $restBaseUrl,
    ) {
    }
}

