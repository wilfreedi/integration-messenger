<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Connector;

use ChatSync\Core\Application\Dto\AttachmentData;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use DateTimeImmutable;

final readonly class SendCrmMessageRequest
{
    /**
     * @param list<AttachmentData> $attachments
     */
    public function __construct(
        public string $externalThreadId,
        public ChannelProvider $channelProvider,
        public string $managerAccountExternalId,
        public string $contactDisplayName,
        public ?string $externalContactChatId,
        public ?string $externalContactUserId,
        public string $body,
        public DateTimeImmutable $occurredAt,
        public string $correlationId,
        public array $attachments = [],
    ) {
    }
}
