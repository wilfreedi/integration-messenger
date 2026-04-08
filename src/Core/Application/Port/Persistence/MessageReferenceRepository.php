<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Persistence;

use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\CrmProvider;
use ChatSync\Core\Domain\ValueObject\MessageId;

interface MessageReferenceRepository
{
    public function hasChannelMessage(
        ChannelProvider $provider,
        string $scopeId,
        string $externalMessageId,
    ): bool;

    public function saveChannelMessage(
        ChannelProvider $provider,
        string $scopeId,
        string $externalMessageId,
        MessageId $messageId,
    ): void;

    public function hasCrmMessage(
        CrmProvider $provider,
        string $scopeId,
        string $externalMessageId,
    ): bool;

    public function saveCrmMessage(
        CrmProvider $provider,
        string $scopeId,
        string $externalMessageId,
        MessageId $messageId,
    ): void;
}

