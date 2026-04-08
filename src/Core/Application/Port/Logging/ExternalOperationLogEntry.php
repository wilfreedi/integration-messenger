<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Logging;

use ChatSync\Core\Domain\Enum\IntegrationDirection;

final readonly class ExternalOperationLogEntry
{
    /**
     * @param array<string, scalar|null> $payload
     */
    public function __construct(
        public string $provider,
        public IntegrationDirection $direction,
        public string $correlationId,
        public string $externalId,
        public string $operation,
        public array $payload = [],
    ) {
    }
}

