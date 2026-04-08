<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

use DateTimeImmutable;

final readonly class ManagerBitrixBinding
{
    public function __construct(
        public string $id,
        public string $managerAccountId,
        public string $portalId,
        public string $connectorId,
        public string $lineId,
        public ?string $operatorUserId,
        public bool $isEnabled,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}

