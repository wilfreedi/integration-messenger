<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Enum;

enum ExternalSystemType: string
{
    case CHANNEL = 'channel';
    case CRM = 'crm';
}

