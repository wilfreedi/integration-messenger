<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Service;

use ChatSync\Core\Application\Port\Connector\CrmConnector;
use ChatSync\Core\Application\Port\Connector\CrmConnectorRegistry;
use ChatSync\Core\Domain\Enum\CrmProvider;
use RuntimeException;

final readonly class ArrayCrmConnectorRegistry implements CrmConnectorRegistry
{
    /**
     * @param array<string, CrmConnector> $connectors
     */
    public function __construct(private array $connectors)
    {
    }

    public function for(CrmProvider $provider): CrmConnector
    {
        if (!isset($this->connectors[$provider->value])) {
            throw new RuntimeException(sprintf('CRM connector for "%s" is not configured.', $provider->value));
        }

        return $this->connectors[$provider->value];
    }
}

