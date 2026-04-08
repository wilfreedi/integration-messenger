<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\Core\Application\Port\Persistence\MessageRepository;
use ChatSync\Core\Domain\Enum\MessageDirection;
use ChatSync\Core\Domain\Model\Message;
use ChatSync\Core\Domain\ValueObject\ConversationId;
use ChatSync\Core\Domain\ValueObject\MessageId;

final class PdoMessageRepository extends AbstractPdoRepository implements MessageRepository
{
    public function findById(MessageId $id): ?Message
    {
        $row = $this->execute(
            'SELECT * FROM messages WHERE id = :id LIMIT 1',
            ['id' => $id->toString()],
        )->fetch();

        return $row === false ? null : $this->map($row);
    }

    public function save(Message $message): void
    {
        $this->execute(
            'INSERT INTO messages (id, conversation_id, direction, body, occurred_at, created_at)
             VALUES (:id, :conversation_id, :direction, :body, :occurred_at, :created_at)
             ON CONFLICT (id) DO UPDATE SET
                direction = EXCLUDED.direction,
                body = EXCLUDED.body,
                occurred_at = EXCLUDED.occurred_at',
            [
                'id' => $message->id()->toString(),
                'conversation_id' => $message->conversationId()->toString(),
                'direction' => $message->direction()->value,
                'body' => $message->body(),
                'occurred_at' => $message->occurredAt()->format(DATE_ATOM),
                'created_at' => $message->createdAt()->format(DATE_ATOM),
            ],
        );
    }

    /**
     * @param array<string, string> $row
     */
    private function map(array $row): Message
    {
        return new Message(
            MessageId::fromString($row['id']),
            ConversationId::fromString($row['conversation_id']),
            MessageDirection::from($row['direction']),
            $row['body'],
            $this->dateTime($row['occurred_at']),
            $this->dateTime($row['created_at']),
        );
    }
}

