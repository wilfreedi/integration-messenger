<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

final readonly class UpsertManagerBitrixBindingResult
{
    public function __construct(
        public string $bindingId,
        public string $managerAccountExternalId,
        public string $portalDomain,
        public string $connectorId,
        public string $lineId,
        public bool $isEnabled,
    ) {
    }
}

