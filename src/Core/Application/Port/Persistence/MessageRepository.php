<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Persistence;

use ChatSync\Core\Domain\Model\Message;
use ChatSync\Core\Domain\ValueObject\MessageId;

interface MessageRepository
{
    public function findById(MessageId $id): ?Message;

    public function save(Message $message): void;
}

