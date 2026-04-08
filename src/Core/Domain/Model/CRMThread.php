<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Model;

use ChatSync\Core\Domain\Enum\CrmProvider;
use ChatSync\Core\Domain\ValueObject\ConversationId;
use ChatSync\Core\Domain\ValueObject\CrmThreadId;
use DateTimeImmutable;

final readonly class CRMThread
{
    public function __construct(
        private CrmThreadId $id,
        private ConversationId $conversationId,
        private CrmProvider $crmProvider,
        private string $externalThreadId,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): CrmThreadId
    {
        return $this->id;
    }

    public function conversationId(): ConversationId
    {
        return $this->conversationId;
    }

    public function crmProvider(): CrmProvider
    {
        return $this->crmProvider;
    }

    public function externalThreadId(): string
    {
        return $this->externalThreadId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

