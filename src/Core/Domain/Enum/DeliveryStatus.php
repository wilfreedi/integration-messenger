<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Enum;

enum DeliveryStatus: string
{
    case SENT = 'sent';
    case FAILED = 'failed';
}

