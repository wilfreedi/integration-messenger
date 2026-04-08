<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Connector;

final readonly class OpenCrmThreadResult
{
    public function __construct(public string $externalThreadId)
    {
    }
}

