<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Enum;

enum ChannelProvider: string
{
    case TELEGRAM = 'telegram';
    case WHATSAPP = 'whatsapp';
    case MAX = 'max';
}

