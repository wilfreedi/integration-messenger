<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Connector;

final readonly class OpenCrmThreadRequest
{
    public function __construct(
        public string $conversationId,
        public string $managerAccountExternalId,
        public string $contactDisplayName,
        public string $correlationId,
    ) {
    }
}

