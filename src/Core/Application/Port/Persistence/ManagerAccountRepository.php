<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Persistence;

use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Model\ManagerAccount;
use ChatSync\Core\Domain\ValueObject\ManagerAccountId;

interface ManagerAccountRepository
{
    public function findById(ManagerAccountId $id): ?ManagerAccount;

    public function findByProviderAndExternalAccountId(
        ChannelProvider $provider,
        string $externalAccountId,
    ): ?ManagerAccount;

    public function save(ManagerAccount $managerAccount): void;
}

