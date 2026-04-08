<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Command;

use ChatSync\Core\Application\Dto\AttachmentData;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\CrmProvider;
use DateTimeImmutable;

final readonly class SyncInboundChannelMessageCommand
{
    /**
     * @param list<AttachmentData> $attachments
     */
    public function __construct(
        public string $eventId,
        public ChannelProvider $channelProvider,
        public CrmProvider $crmProvider,
        public string $managerAccountExternalId,
        public string $contactExternalChatId,
        public ?string $contactExternalUserId,
        public string $contactDisplayName,
        public string $externalMessageId,
        public string $body,
        public DateTimeImmutable $occurredAt,
        public array $attachments = [],
    ) {
    }
}

