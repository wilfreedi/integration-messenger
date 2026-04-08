<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

use DateTimeImmutable;

final readonly class BitrixPortalInstall
{
    public function __construct(
        public string $portalId,
        public string $installId,
        public string $portalDomain,
        public ?string $memberId,
        public string $appStatus,
        public string $accessToken,
        public string $refreshToken,
        public DateTimeImmutable $expiresAt,
        public string $scope,
        public string $applicationToken,
        public string $restBaseUrl,
        public ?string $oauthClientId,
        public ?string $oauthClientSecret,
        public ?string $oauthServerEndpoint,
        public bool $active,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
