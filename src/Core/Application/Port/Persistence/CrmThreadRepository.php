<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Persistence;

use ChatSync\Core\Domain\Enum\CrmProvider;
use ChatSync\Core\Domain\Model\CRMThread;
use ChatSync\Core\Domain\ValueObject\ConversationId;

interface CrmThreadRepository
{
    public function findByConversationAndProvider(ConversationId $conversationId, CrmProvider $provider): ?CRMThread;

    public function findByProviderAndExternalThreadId(CrmProvider $provider, string $externalThreadId): ?CRMThread;

    public function save(CRMThread $crmThread): void;
}

