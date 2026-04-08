<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

interface BitrixPortalInstallRepository
{
    public function upsert(BitrixPortalInstall $install): void;

    public function findByPortalDomain(string $portalDomain): ?BitrixPortalInstall;
}

