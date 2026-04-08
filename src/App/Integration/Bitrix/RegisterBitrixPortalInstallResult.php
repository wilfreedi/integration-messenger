<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

final readonly class RegisterBitrixPortalInstallResult
{
    public function __construct(
        public string $portalDomain,
        public string $portalId,
        public string $installId,
        public string $expiresAt,
    ) {
    }
}

