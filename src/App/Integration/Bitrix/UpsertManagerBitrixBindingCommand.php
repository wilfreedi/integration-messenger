<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

use ChatSync\Core\Domain\Enum\ChannelProvider;

final readonly class UpsertManagerBitrixBindingCommand
{
    public function __construct(
        public ChannelProvider $channelProvider,
        public string $managerAccountExternalId,
        public string $portalDomain,
        public string $connectorId,
        public string $lineId,
        public ?string $operatorUserId,
        public bool $isEnabled,
    ) {
    }
}

