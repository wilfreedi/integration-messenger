<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\Core\Application\Port\Persistence\MessageReferenceRepository;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\CrmProvider;
use ChatSync\Core\Domain\Enum\ExternalSystemType;
use ChatSync\Core\Domain\ValueObject\MessageId;
use ChatSync\Shared\Domain\IdGenerator;

final class PdoMessageReferenceRepository extends AbstractPdoRepository implements MessageReferenceRepository
{
    public function __construct(\PDO $pdo, private readonly IdGenerator $idGenerator)
    {
        parent::__construct($pdo);
    }

    public function hasChannelMessage(
        ChannelProvider $provider,
        string $scopeId,
        string $externalMessageId,
    ): bool {
        return $this->exists(ExternalSystemType::CHANNEL->value, $provider->value, $scopeId, $externalMessageId);
    }

    public function saveChannelMessage(
        ChannelProvider $provider,
        string $scopeId,
        string $externalMessageId,
        MessageId $messageId,
    ): void {
        $this->saveReference(ExternalSystemType::CHANNEL->value, $provider->value, $scopeId, $externalMessageId, $messageId);
    }

    public function hasCrmMessage(
        CrmProvider $provider,
        string $scopeId,
        string $externalMessageId,
    ): bool {
        return $this->exists(ExternalSystemType::CRM->value, $provider->value, $scopeId, $externalMessageId);
    }

    public function saveCrmMessage(
        CrmProvider $provider,
        string $scopeId,
        string $externalMessageId,
        MessageId $messageId,
    ): void {
        $this->saveReference(ExternalSystemType::CRM->value, $provider->value, $scopeId, $externalMessageId, $messageId);
    }

    private function exists(string $systemType, string $provider, string $scopeId, string $externalMessageId): bool
    {
        $value = $this->execute(
            'SELECT 1 FROM message_mappings
             WHERE system_type = :system_type AND provider = :provider AND scope_id = :scope_id AND external_message_id = :external_message_id
             LIMIT 1',
            [
                'system_type' => $systemType,
                'provider' => $provider,
                'scope_id' => $scopeId,
                'external_message_id' => $externalMessageId,
            ],
        )->fetchColumn();

        return $value !== false;
    }

    private function saveReference(
        string $systemType,
        string $provider,
        string $scopeId,
        string $externalMessageId,
        MessageId $messageId,
    ): void {
        $this->execute(
            'INSERT INTO message_mappings (id, system_type, provider, scope_id, external_message_id, internal_message_id, created_at)
             VALUES (:id, :system_type, :provider, :scope_id, :external_message_id, :internal_message_id, NOW())
             ON CONFLICT (system_type, provider, scope_id, external_message_id) DO NOTHING',
            [
                'id' => $this->idGenerator->next(),
                'system_type' => $systemType,
                'provider' => $provider,
                'scope_id' => $scopeId,
                'external_message_id' => $externalMessageId,
                'internal_message_id' => $messageId->toString(),
            ],
        );
    }
}
