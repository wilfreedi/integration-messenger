<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Enum;

enum ConversationStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
}

