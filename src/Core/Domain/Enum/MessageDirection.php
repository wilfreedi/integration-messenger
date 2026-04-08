<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Enum;

enum MessageDirection: string
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';
}

