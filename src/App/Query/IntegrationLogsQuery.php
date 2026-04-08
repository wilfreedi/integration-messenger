<?php

declare(strict_types=1);

namespace ChatSync\App\Query;

use PDO;

final readonly class IntegrationLogsQuery
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function auditLogs(int $limit = 200): array
    {
        $safeLimit = max(1, min(1000, $limit));

        $statement = $this->pdo->prepare(
            'SELECT
                provider,
                direction,
                correlation_id,
                external_id,
                operation,
                payload,
                created_at
             FROM audit_logs
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        if ($rows === false) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $payloadRaw = $row['payload'] ?? null;
            $payload = null;
            if (is_string($payloadRaw) && trim($payloadRaw) !== '') {
                $decoded = json_decode($payloadRaw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $result[] = [
                'provider' => (string) ($row['provider'] ?? ''),
                'direction' => (string) ($row['direction'] ?? ''),
                'operation' => (string) ($row['operation'] ?? ''),
                'correlation_id' => (string) ($row['correlation_id'] ?? ''),
                'external_id' => (string) ($row['external_id'] ?? ''),
                'payload' => $payload,
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $result;
    }
}

