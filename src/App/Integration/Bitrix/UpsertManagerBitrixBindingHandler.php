<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

use ChatSync\Core\Application\Exception\ManagerAccountNotFound;
use ChatSync\Core\Application\Port\Persistence\ManagerAccountRepository;
use ChatSync\Shared\Domain\Clock;
use ChatSync\Shared\Domain\IdGenerator;
use InvalidArgumentException;

final readonly class UpsertManagerBitrixBindingHandler
{
    public function __construct(
        private ManagerAccountRepository $managerAccounts,
        private BitrixPortalInstallRepository $installs,
        private ManagerBitrixBindingRepository $bindings,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function __invoke(UpsertManagerBitrixBindingCommand $command): UpsertManagerBitrixBindingResult
    {
        $managerAccount = $this->managerAccounts->findByProviderAndExternalAccountId(
            $command->channelProvider,
            $command->managerAccountExternalId,
        );

        if ($managerAccount === null) {
            throw new ManagerAccountNotFound(sprintf(
                'Manager account not found for provider "%s" and external id "%s".',
                $command->channelProvider->value,
                $command->managerAccountExternalId,
            ));
        }

        $install = $this->installs->findByPortalDomain($command->portalDomain);
        if ($install === null) {
            throw new InvalidArgumentException(sprintf(
                'Bitrix portal "%s" is not connected. Install app first.',
                $command->portalDomain,
            ));
        }

        $now = $this->clock->now();
        $binding = new ManagerBitrixBinding(
            id: $this->idGenerator->next(),
            managerAccountId: $managerAccount->id()->toString(),
            portalId: $install->portalId,
            connectorId: $command->connectorId,
            lineId: $command->lineId,
            operatorUserId: $command->operatorUserId,
            isEnabled: $command->isEnabled,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->bindings->upsert($binding);

        return new UpsertManagerBitrixBindingResult(
            bindingId: $binding->id,
            managerAccountExternalId: $command->managerAccountExternalId,
            portalDomain: $command->portalDomain,
            connectorId: $binding->connectorId,
            lineId: $binding->lineId,
            isEnabled: $binding->isEnabled,
        );
    }
}

