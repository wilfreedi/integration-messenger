<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

interface ManagerBitrixBindingRepository
{
    public function upsert(ManagerBitrixBinding $binding): void;
}

