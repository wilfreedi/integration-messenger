<?php

declare(strict_types=1);

namespace ChatSync\App\Query;

use PDO;

final readonly class MessageMappingLookup
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findChannelExternalMessageIdByInternalMessageId(
        string $internalMessageId,
        string $channelProvider,
    ): ?string {
        $statement = $this->pdo->prepare(
            'SELECT external_message_id
             FROM message_mappings
             WHERE system_type = :system_type
               AND provider = :provider
               AND internal_message_id = :internal_message_id
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $statement->execute([
            'system_type' => 'channel',
            'provider' => $channelProvider,
            'internal_message_id' => $internalMessageId,
        ]);

        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function findChannelExternalMessageIdByCrmExternalMessage(
        string $crmProvider,
        string $externalThreadId,
        string $crmExternalMessageId,
        string $channelProvider,
    ): ?string {
        $statement = $this->pdo->prepare(
            'SELECT mm_channel.external_message_id
             FROM crm_threads ct
             INNER JOIN message_mappings mm_crm
                 ON mm_crm.system_type = :crm_system_type
                AND mm_crm.provider = :crm_provider
                AND mm_crm.scope_id = ct.id
                AND mm_crm.external_message_id = :crm_external_message_id
             INNER JOIN message_mappings mm_channel
                 ON mm_channel.system_type = :channel_system_type
                AND mm_channel.provider = :channel_provider
                AND mm_channel.internal_message_id = mm_crm.internal_message_id
             WHERE ct.crm_provider = :crm_provider
               AND ct.external_thread_id = :external_thread_id
             ORDER BY mm_channel.created_at DESC
             LIMIT 1'
        );
        $statement->execute([
            'crm_system_type' => 'crm',
            'crm_provider' => $crmProvider,
            'crm_external_message_id' => $crmExternalMessageId,
            'channel_system_type' => 'channel',
            'channel_provider' => $channelProvider,
            'external_thread_id' => $externalThreadId,
        ]);

        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function findManagerAccountExternalIdByInternalMessageId(string $internalMessageId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT ma.external_account_id
             FROM messages m
             INNER JOIN conversations c ON c.id = m.conversation_id
             INNER JOIN manager_accounts ma ON ma.id = c.manager_account_id
             WHERE m.id = :internal_message_id
             LIMIT 1'
        );
        $statement->execute([
            'internal_message_id' => $internalMessageId,
        ]);

        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function findManagerAccountExternalIdByCrmExternalMessage(
        string $crmProvider,
        string $externalThreadId,
        string $crmExternalMessageId,
    ): ?string {
        $statement = $this->pdo->prepare(
            'SELECT ma.external_account_id
             FROM crm_threads ct
             INNER JOIN message_mappings mm_crm
                 ON mm_crm.system_type = :crm_system_type
                AND mm_crm.provider = :crm_provider
                AND mm_crm.scope_id = ct.id
                AND mm_crm.external_message_id = :crm_external_message_id
             INNER JOIN messages m ON m.id = mm_crm.internal_message_id
             INNER JOIN conversations c ON c.id = m.conversation_id
             INNER JOIN manager_accounts ma ON ma.id = c.manager_account_id
             WHERE ct.crm_provider = :crm_provider
               AND ct.external_thread_id = :external_thread_id
             ORDER BY mm_crm.created_at DESC
             LIMIT 1'
        );
        $statement->execute([
            'crm_system_type' => 'crm',
            'crm_provider' => $crmProvider,
            'crm_external_message_id' => $crmExternalMessageId,
            'external_thread_id' => $externalThreadId,
        ]);

        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }
}
