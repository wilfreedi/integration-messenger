<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Model;

use ChatSync\Core\Domain\Enum\MessageDirection;
use ChatSync\Core\Domain\ValueObject\ConversationId;
use ChatSync\Core\Domain\ValueObject\MessageId;
use DateTimeImmutable;

final readonly class Message
{
    public function __construct(
        private MessageId $id,
        private ConversationId $conversationId,
        private MessageDirection $direction,
        private string $body,
        private DateTimeImmutable $occurredAt,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public static function inbound(
        MessageId $id,
        ConversationId $conversationId,
        string $body,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $conversationId, MessageDirection::INBOUND, $body, $occurredAt, $createdAt);
    }

    public static function outbound(
        MessageId $id,
        ConversationId $conversationId,
        string $body,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $conversationId, MessageDirection::OUTBOUND, $body, $occurredAt, $createdAt);
    }

    public function id(): MessageId
    {
        return $this->id;
    }

    public function conversationId(): ConversationId
    {
        return $this->conversationId;
    }

    public function direction(): MessageDirection
    {
        return $this->direction;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

