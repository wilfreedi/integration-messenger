<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

interface BitrixRoutingResolver
{
    public function resolveForManagerAccount(string $channelProvider, string $managerAccountExternalId): ?BitrixRoutingContext;
}

