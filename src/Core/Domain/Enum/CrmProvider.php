<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Enum;

enum CrmProvider: string
{
    case BITRIX = 'bitrix';
    case AMOCRM = 'amocrm';
}

