<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Logging;

use ChatSync\Core\Application\Port\Logging\ExternalOperationLogEntry;
use ChatSync\Core\Application\Port\Logging\ExternalOperationLogger;
use ChatSync\Shared\Domain\Clock;
use ChatSync\Shared\Domain\IdGenerator;
use PDO;

final readonly class PdoExternalOperationLogger implements ExternalOperationLogger
{
    public function __construct(
        private PDO $pdo,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function log(ExternalOperationLogEntry $entry): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (id, provider, direction, correlation_id, external_id, operation, payload, created_at)
             VALUES (:id, :provider, :direction, :correlation_id, :external_id, :operation, :payload, :created_at)',
        );

        $statement->execute([
            'id' => $this->idGenerator->next(),
            'provider' => $entry->provider,
            'direction' => $entry->direction->value,
            'correlation_id' => $entry->correlationId,
            'external_id' => $entry->externalId,
            'operation' => $entry->operation,
            'payload' => $entry->payload === [] ? null : json_encode($entry->payload, JSON_THROW_ON_ERROR),
            'created_at' => $this->clock->now()->format(DATE_ATOM),
        ]);
    }
}

