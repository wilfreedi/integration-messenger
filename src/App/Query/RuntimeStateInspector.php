<?php

declare(strict_types=1);

namespace ChatSync\App\Query;

use PDO;

final readonly class RuntimeStateInspector
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'managers' => $this->fetchAll('SELECT id, display_name, created_at FROM managers ORDER BY created_at ASC'),
            'manager_accounts' => $this->fetchAll('SELECT id, manager_id, channel_provider, external_account_id, status, created_at FROM manager_accounts ORDER BY created_at ASC'),
            'contacts' => $this->fetchAll('SELECT id, display_name, primary_phone, created_at FROM contacts ORDER BY created_at ASC'),
            'contact_identities' => $this->fetchAll('SELECT id, contact_id, provider, identity_type, identity_value, is_primary, created_at FROM contact_identities ORDER BY created_at ASC'),
            'conversations' => $this->fetchAll('SELECT id, manager_account_id, contact_id, status, opened_at, last_activity_at FROM conversations ORDER BY opened_at ASC'),
            'crm_threads' => $this->fetchAll('SELECT id, conversation_id, crm_provider, external_thread_id, created_at FROM crm_threads ORDER BY created_at ASC'),
            'messages' => $this->fetchAll('SELECT id, conversation_id, direction, body, occurred_at, created_at FROM messages ORDER BY created_at ASC'),
            'attachments' => $this->fetchAll('SELECT id, message_id, attachment_type, external_file_id, file_name, mime_type, created_at FROM attachments ORDER BY created_at ASC'),
            'deliveries' => $this->fetchAll('SELECT id, message_id, system_type, provider, direction, external_id, correlation_id, status, created_at FROM deliveries ORDER BY created_at ASC'),
            'message_mappings' => $this->fetchAll('SELECT id, system_type, provider, scope_id, external_message_id, internal_message_id, created_at FROM message_mappings ORDER BY created_at ASC'),
            'processed_events' => $this->fetchAll('SELECT source, event_id, processed_at FROM processed_events ORDER BY processed_at ASC'),
            'audit_logs' => $this->fetchAll('SELECT id, provider, direction, correlation_id, external_id, operation, payload, created_at FROM audit_logs ORDER BY created_at ASC'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        $rows = $statement->fetchAll();

        return $rows === false ? [] : $rows;
    }
}

