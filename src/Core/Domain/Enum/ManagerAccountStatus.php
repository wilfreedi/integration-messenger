<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Enum;

enum ManagerAccountStatus: string
{
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
}

