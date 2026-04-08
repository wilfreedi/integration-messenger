<?php

declare(strict_types=1);

namespace ChatSync\App\Query;

use PDO;

final readonly class ManagerAccountsQuery
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $channelProvider = null): array
    {
        $sql = 'SELECT id, channel_provider, external_account_id, status, created_at
                FROM manager_accounts';
        $params = [];

        if ($channelProvider !== null && $channelProvider !== '') {
            $sql .= ' WHERE channel_provider = :channel_provider';
            $params['channel_provider'] = $channelProvider;
        }

        $sql .= ' ORDER BY created_at ASC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        if ($rows === false) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => (string) $row['id'],
                'channel_provider' => (string) $row['channel_provider'],
                'external_account_id' => (string) $row['external_account_id'],
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $result;
    }
}
