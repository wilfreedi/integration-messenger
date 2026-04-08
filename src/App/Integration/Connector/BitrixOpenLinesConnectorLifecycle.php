<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

interface BitrixOpenLinesConnectorLifecycle
{
    public function ensure(
        string $baseUrl,
        string $connectorId,
        string $lineId,
        ?string $authToken,
    ): void;
}

