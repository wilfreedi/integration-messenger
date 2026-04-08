<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Http\Validator\InboundCrmMessageValidator;
use ChatSync\Core\Application\Handler\SyncOutboundCrmMessageHandler;

final readonly class CrmMessageWebhookController
{
    public function __construct(
        private InboundCrmMessageValidator $validator,
        private SyncOutboundCrmMessageHandler $handler,
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

