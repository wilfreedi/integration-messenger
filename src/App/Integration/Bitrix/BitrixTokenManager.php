<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

interface BitrixTokenManager
{
    public function ensureValidRoute(BitrixRoutingContext $route, string $managerAccountExternalId): BitrixRoutingContext;
}

