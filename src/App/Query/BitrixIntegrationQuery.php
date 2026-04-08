<?php

declare(strict_types=1);

namespace ChatSync\App\Query;

use PDO;

final readonly class BitrixIntegrationQuery
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function portals(): array
    {
        $statement = $this->pdo->query(
            'SELECT
                p.id AS portal_id,
                p.portal_domain,
                p.member_id,
                p.app_status,
                p.created_at AS portal_created_at,
                p.updated_at AS portal_updated_at,
                i.id AS install_id,
                i.expires_at,
                i.scope,
                i.active,
                i.oauth_client_id,
                i.oauth_client_secret,
                i.oauth_server_endpoint,
                i.updated_at AS install_updated_at
             FROM bitrix_portals p
             INNER JOIN bitrix_app_installs i ON i.portal_id = p.id
             ORDER BY p.updated_at DESC'
        );
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
                'portal_id' => $row['portal_id'],
                'portal_domain' => $row['portal_domain'],
                'member_id' => $row['member_id'],
                'app_status' => $row['app_status'],
                'scope' => $row['scope'],
                'expires_at' => $row['expires_at'],
                'active' => $this->toBool($row['active']),
                'oauth_refresh_ready' => $this->oauthRefreshReady(
                    $row['oauth_client_id'] ?? null,
                    $row['oauth_client_secret'] ?? null,
                ),
                'oauth_server_endpoint' => $row['oauth_server_endpoint'],
                'created_at' => $row['portal_created_at'],
                'updated_at' => $row['portal_updated_at'],
                'install_id' => $row['install_id'],
                'install_updated_at' => $row['install_updated_at'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bindings(): array
    {
        $statement = $this->pdo->query(
            'SELECT
                b.id,
                ma.channel_provider,
                ma.external_account_id AS manager_account_external_id,
                p.portal_domain,
                b.line_id,
                b.operator_user_id,
                b.is_enabled,
                b.created_at,
                b.updated_at
             FROM manager_bitrix_bindings b
             INNER JOIN manager_accounts ma ON ma.id = b.manager_account_id
             INNER JOIN bitrix_portals p ON p.id = b.portal_id
             ORDER BY b.updated_at DESC'
        );
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
                'id' => $row['id'],
                'channel_provider' => $row['channel_provider'],
                'manager_account_external_id' => $row['manager_account_external_id'],
                'portal_domain' => $row['portal_domain'],
                'line_id' => $row['line_id'],
                'operator_user_id' => $row['operator_user_id'],
                'is_enabled' => $this->toBool($row['is_enabled']),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $result;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 't', 'yes', 'y', 'on'], true);
        }

        return false;
    }

    private function oauthRefreshReady(mixed $clientId, mixed $clientSecret): bool
    {
        return is_string($clientId)
            && trim($clientId) !== ''
            && is_string($clientSecret)
            && trim($clientSecret) !== '';
    }
}
