<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Service;

use ChatSync\Core\Application\Port\Connector\ChannelConnector;
use ChatSync\Core\Application\Port\Connector\ChannelConnectorRegistry;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use RuntimeException;

final readonly class ArrayChannelConnectorRegistry implements ChannelConnectorRegistry
{
    /**
     * @param array<string, ChannelConnector> $connectors
     */
    public function __construct(private array $connectors)
    {
    }

    public function for(ChannelProvider $provider): ChannelConnector
    {
        if (!isset($this->connectors[$provider->value])) {
            throw new RuntimeException(sprintf('Channel connector for "%s" is not configured.', $provider->value));
        }

        return $this->connectors[$provider->value];
    }
}

