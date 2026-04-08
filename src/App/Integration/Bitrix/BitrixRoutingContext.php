<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

use DateTimeImmutable;

final readonly class BitrixRoutingContext
{
    public function __construct(
        public string $restBaseUrl,
        public string $connectorId,
        public string $lineId,
        public ?string $accessToken,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}

