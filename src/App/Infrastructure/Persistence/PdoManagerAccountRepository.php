<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\Core\Application\Port\Persistence\ManagerAccountRepository;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\ManagerAccountStatus;
use ChatSync\Core\Domain\Model\ManagerAccount;
use ChatSync\Core\Domain\ValueObject\ManagerAccountId;
use ChatSync\Core\Domain\ValueObject\ManagerId;

final class PdoManagerAccountRepository extends AbstractPdoRepository implements ManagerAccountRepository
{
    public function findById(ManagerAccountId $id): ?ManagerAccount
    {
        $row = $this->execute(
            'SELECT * FROM manager_accounts WHERE id = :id LIMIT 1',
            ['id' => $id->toString()],
        )->fetch();

        return $row === false ? null : $this->map($row);
    }

    public function findByProviderAndExternalAccountId(
        ChannelProvider $provider,
        string $externalAccountId,
    ): ?ManagerAccount {
        $row = $this->execute(
            'SELECT * FROM manager_accounts WHERE channel_provider = :provider AND external_account_id = :external_account_id LIMIT 1',
            [
                'provider' => $provider->value,
                'external_account_id' => $externalAccountId,
            ],
        )->fetch();

        return $row === false ? null : $this->map($row);
    }

    public function save(ManagerAccount $managerAccount): void
    {
        $this->execute(
            'INSERT INTO manager_accounts (id, manager_id, channel_provider, external_account_id, status, created_at)
             VALUES (:id, :manager_id, :channel_provider, :external_account_id, :status, :created_at)
             ON CONFLICT (id) DO UPDATE SET
                manager_id = EXCLUDED.manager_id,
                channel_provider = EXCLUDED.channel_provider,
                external_account_id = EXCLUDED.external_account_id,
                status = EXCLUDED.status',
            [
                'id' => $managerAccount->id()->toString(),
                'manager_id' => $managerAccount->managerId()->toString(),
                'channel_provider' => $managerAccount->channelProvider()->value,
                'external_account_id' => $managerAccount->externalAccountId(),
                'status' => $managerAccount->status()->value,
                'created_at' => $managerAccount->createdAt()->format(DATE_ATOM),
            ],
        );
    }

    /**
     * @param array<string, string> $row
     */
    private function map(array $row): ManagerAccount
    {
        return new ManagerAccount(
            ManagerAccountId::fromString($row['id']),
            ManagerId::fromString($row['manager_id']),
            ChannelProvider::from($row['channel_provider']),
            $row['external_account_id'],
            ManagerAccountStatus::from($row['status']),
            $this->dateTime($row['created_at']),
        );
    }
}

