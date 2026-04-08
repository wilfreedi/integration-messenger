<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

use ChatSync\Shared\Domain\Clock;
use ChatSync\Shared\Domain\IdGenerator;

final readonly class RegisterBitrixPortalInstallHandler
{
    public function __construct(
        private BitrixPortalInstallRepository $repository,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function __invoke(RegisterBitrixPortalInstallCommand $command): RegisterBitrixPortalInstallResult
    {
        $now = $this->clock->now();
        $existing = $this->repository->findByPortalDomain($command->portalDomain);
        $portalId = $existing?->portalId ?? $this->idGenerator->next();
        $installId = $existing?->installId ?? $this->idGenerator->next();
        $createdAt = $existing?->createdAt ?? $now;
        $expiresAt = $now->modify(sprintf('+%d seconds', max(1, $command->expiresInSeconds)));

        $install = new BitrixPortalInstall(
            portalId: $portalId,
            installId: $installId,
            portalDomain: $command->portalDomain,
            memberId: $command->memberId,
            appStatus: 'installed',
            accessToken: $command->accessToken,
            refreshToken: $command->refreshToken,
            expiresAt: $expiresAt,
            scope: $command->scope,
            applicationToken: $command->applicationToken,
            restBaseUrl: $command->restBaseUrl,
            active: true,
            createdAt: $createdAt,
            updatedAt: $now,
        );

        $this->repository->upsert($install);

        return new RegisterBitrixPortalInstallResult(
            portalDomain: $install->portalDomain,
            portalId: $install->portalId,
            installId: $install->installId,
            expiresAt: $install->expiresAt->format(DATE_ATOM),
        );
    }
}

