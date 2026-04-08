<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Dto;

use ChatSync\Core\Application\Command\SyncOutboundCrmMessageCommand;

final readonly class BitrixOpenLinesOperatorMessage
{
    public function __construct(
        public SyncOutboundCrmMessageCommand $command,
        public ?string $imMessageId,
        public ?string $imChatId,
    ) {
    }
}
