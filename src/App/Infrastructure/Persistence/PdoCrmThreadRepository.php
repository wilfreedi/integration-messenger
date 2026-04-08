<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\Core\Application\Port\Persistence\CrmThreadRepository;
use ChatSync\Core\Domain\Enum\CrmProvider;
use ChatSync\Core\Domain\Model\CRMThread;
use ChatSync\Core\Domain\ValueObject\ConversationId;
use ChatSync\Core\Domain\ValueObject\CrmThreadId;

final class PdoCrmThreadRepository extends AbstractPdoRepository implements CrmThreadRepository
{
    public function findByConversationAndProvider(ConversationId $conversationId, CrmProvider $provider): ?CRMThread
    {
        $row = $this->execute(
            'SELECT * FROM crm_threads WHERE conversation_id = :conversation_id AND crm_provider = :crm_provider LIMIT 1',
            [
                'conversation_id' => $conversationId->toString(),
                'crm_provider' => $provider->value,
            ],
        )->fetch();

        return $row === false ? null : $this->map($row);
    }

    public function findByProviderAndExternalThreadId(CrmProvider $provider, string $externalThreadId): ?CRMThread
    {
        $row = $this->execute(
            'SELECT * FROM crm_threads WHERE crm_provider = :crm_provider AND external_thread_id = :external_thread_id LIMIT 1',
            [
                'crm_provider' => $provider->value,
                'external_thread_id' => $externalThreadId,
            ],
        )->fetch();

        return $row === false ? null : $this->map($row);
    }

    public function save(CRMThread $crmThread): void
    {
        $this->execute(
            'INSERT INTO crm_threads (id, conversation_id, crm_provider, external_thread_id, created_at)
             VALUES (:id, :conversation_id, :crm_provider, :external_thread_id, :created_at)
             ON CONFLICT (id) DO UPDATE SET
                crm_provider = EXCLUDED.crm_provider,
                external_thread_id = EXCLUDED.external_thread_id',
            [
                'id' => $crmThread->id()->toString(),
                'conversation_id' => $crmThread->conversationId()->toString(),
                'crm_provider' => $crmThread->crmProvider()->value,
                'external_thread_id' => $crmThread->externalThreadId(),
                'created_at' => $crmThread->createdAt()->format(DATE_ATOM),
            ],
        );
    }

    /**
     * @param array<string, string> $row
     */
    private function map(array $row): CRMThread
    {
        return new CRMThread(
            CrmThreadId::fromString($row['id']),
            ConversationId::fromString($row['conversation_id']),
            CrmProvider::from($row['crm_provider']),
            $row['external_thread_id'],
            $this->dateTime($row['created_at']),
        );
    }
}

