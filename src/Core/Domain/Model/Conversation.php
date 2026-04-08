<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Model;

use ChatSync\Core\Domain\Enum\ConversationStatus;
use ChatSync\Core\Domain\ValueObject\ContactId;
use ChatSync\Core\Domain\ValueObject\ConversationId;
use ChatSync\Core\Domain\ValueObject\ManagerAccountId;
use DateTimeImmutable;

final readonly class Conversation
{
    public function __construct(
        private ConversationId $id,
        private ManagerAccountId $managerAccountId,
        private ContactId $contactId,
        private ConversationStatus $status,
        private DateTimeImmutable $openedAt,
        private DateTimeImmutable $lastActivityAt,
    ) {
    }

    public static function start(
        ConversationId $id,
        ManagerAccountId $managerAccountId,
        ContactId $contactId,
        DateTimeImmutable $openedAt,
    ): self {
        return new self(
            $id,
            $managerAccountId,
            $contactId,
            ConversationStatus::OPEN,
            $openedAt,
            $openedAt,
        );
    }

    public function recordActivity(DateTimeImmutable $occurredAt): self
    {
        return new self(
            $this->id,
            $this->managerAccountId,
            $this->contactId,
            ConversationStatus::OPEN,
            $this->openedAt,
            $occurredAt,
        );
    }

    public function id(): ConversationId
    {
        return $this->id;
    }

    public function managerAccountId(): ManagerAccountId
    {
        return $this->managerAccountId;
    }

    public function contactId(): ContactId
    {
        return $this->contactId;
    }

    public function status(): ConversationStatus
    {
        return $this->status;
    }

    public function openedAt(): DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function lastActivityAt(): DateTimeImmutable
    {
        return $this->lastActivityAt;
    }
}

