<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

final readonly class BitrixOpenLinesSendMessageResult
{
    public function __construct(
        public string $externalMessageId,
        public string $sessionId,
        public string $sessionChatId,
    ) {
    }
}

