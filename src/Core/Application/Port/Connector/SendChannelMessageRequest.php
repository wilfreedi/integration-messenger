<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Connector;

use ChatSync\Core\Application\Dto\AttachmentData;
use DateTimeImmutable;

final readonly class SendChannelMessageRequest
{
    /**
     * @param list<AttachmentData> $attachments
     */
    public function __construct(
        public string $managerAccountExternalId,
        public string $externalChatId,
        public string $body,
        public DateTimeImmutable $occurredAt,
        public string $correlationId,
        public array $attachments = [],
    ) {
    }
}

