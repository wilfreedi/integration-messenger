<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\Core\Application\Port\Persistence\ConversationRepository;
use ChatSync\Core\Domain\Enum\ConversationStatus;
use ChatSync\Core\Domain\Model\Conversation;
use ChatSync\Core\Domain\ValueObject\ContactId;
use ChatSync\Core\Domain\ValueObject\ConversationId;
use ChatSync\Core\Domain\ValueObject\ManagerAccountId;

final class PdoConversationRepository extends AbstractPdoRepository implements ConversationRepository
{
    public function findByManagerAccountAndContact(
        ManagerAccountId $managerAccountId,
        ContactId $contactId,
    ): ?Conversation {
        $row = $this->execute(
            'SELECT * FROM conversations WHERE manager_account_id = :manager_account_id AND contact_id = :contact_id LIMIT 1',
            [
                'manager_account_id' => $managerAccountId->toString(),
                'contact_id' => $contactId->toString(),
            ],
        )->fetch();

        return $row === false ? null : $this->map($row);
    }

    public function findById(ConversationId $id): ?Conversation
    {
        $row = $this->execute(
            'SELECT * FROM conversations WHERE id = :id LIMIT 1',
            ['id' => $id->toString()],
        )->fetch();

        return $row === false ? null : $this->map($row);
    }

    public function save(Conversation $conversation): void
    {
        $this->execute(
            'INSERT INTO conversations (id, manager_account_id, contact_id, status, opened_at, last_activity_at)
             VALUES (:id, :manager_account_id, :contact_id, :status, :opened_at, :last_activity_at)
             ON CONFLICT (id) DO UPDATE SET
                status = EXCLUDED.status,
                last_activity_at = EXCLUDED.last_activity_at',
            [
                'id' => $conversation->id()->toString(),
                'manager_account_id' => $conversation->managerAccountId()->toString(),
                'contact_id' => $conversation->contactId()->toString(),
                'status' => $conversation->status()->value,
                'opened_at' => $conversation->openedAt()->format(DATE_ATOM),
                'last_activity_at' => $conversation->lastActivityAt()->format(DATE_ATOM),
            ],
        );
    }

    /**
     * @param array<string, string> $row
     */
    private function map(array $row): Conversation
    {
        return new Conversation(
            ConversationId::fromString($row['id']),
            ManagerAccountId::fromString($row['manager_account_id']),
            ContactId::fromString($row['contact_id']),
            ConversationStatus::from($row['status']),
            $this->dateTime($row['opened_at']),
            $this->dateTime($row['last_activity_at']),
        );
    }
}

