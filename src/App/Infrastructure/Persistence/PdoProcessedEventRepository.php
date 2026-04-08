<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\Core\Application\Port\Persistence\ProcessedEventRepository;
use ChatSync\Shared\Domain\Clock;

final class PdoProcessedEventRepository extends AbstractPdoRepository implements ProcessedEventRepository
{
    public function __construct(\PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function has(string $source, string $eventId): bool
    {
        $value = $this->execute(
            'SELECT 1 FROM processed_events WHERE source = :source AND event_id = :event_id LIMIT 1',
            [
                'source' => $source,
                'event_id' => $eventId,
            ],
        )->fetchColumn();

        return $value !== false;
    }

    public function add(string $source, string $eventId): void
    {
        $this->execute(
            'INSERT INTO processed_events (source, event_id, processed_at)
             VALUES (:source, :event_id, :processed_at)
             ON CONFLICT (source, event_id) DO NOTHING',
            [
                'source' => $source,
                'event_id' => $eventId,
                'processed_at' => $this->clock->now()->format(DATE_ATOM),
            ],
        );
    }
}

