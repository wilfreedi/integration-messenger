<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Http\Validator\InboundChannelMessageValidator;
use ChatSync\Core\Application\Handler\SyncInboundChannelMessageHandler;

final readonly class ChannelMessageWebhookController
{
    public function __construct(
        private InboundChannelMessageValidator $validator,
        private SyncInboundChannelMessageHandler $handler,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $result = ($this->handler)($this->validator->validate($payload));

        return [
            'status' => $result->processed ? 'processed' : 'skipped',
            'reason' => $result->reason,
            'message_id' => $result->messageId,
        ];
    }
}

