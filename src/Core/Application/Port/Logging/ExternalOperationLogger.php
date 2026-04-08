<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Logging;

interface ExternalOperationLogger
{
    public function log(ExternalOperationLogEntry $entry): void;
}

