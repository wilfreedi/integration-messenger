<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Persistence;

use ChatSync\Core\Domain\Model\Conversation;
use ChatSync\Core\Domain\ValueObject\ContactId;
use ChatSync\Core\Domain\ValueObject\ConversationId;
use ChatSync\Core\Domain\ValueObject\ManagerAccountId;

interface ConversationRepository
{
    public function findByManagerAccountAndContact(
        ManagerAccountId $managerAccountId,
        ContactId $contactId,
    ): ?Conversation;

    public function findById(ConversationId $id): ?Conversation;

    /**
     * @return list<Conversation>
     */
    public function findByContact(ContactId $contactId): array;

    public function save(Conversation $conversation): void;
}
