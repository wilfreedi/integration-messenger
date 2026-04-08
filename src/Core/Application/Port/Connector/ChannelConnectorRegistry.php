<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Connector;

use ChatSync\Core\Domain\Enum\ChannelProvider;

interface ChannelConnectorRegistry
{
    public function for(ChannelProvider $provider): ChannelConnector;
}

