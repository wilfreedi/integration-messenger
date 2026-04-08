<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Connector;

use ChatSync\Core\Domain\Enum\CrmProvider;

interface CrmConnectorRegistry
{
    public function for(CrmProvider $provider): CrmConnector;
}

