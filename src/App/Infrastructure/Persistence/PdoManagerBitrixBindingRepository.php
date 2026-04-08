<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\App\Integration\Bitrix\BitrixRoutingContext;
use ChatSync\App\Integration\Bitrix\BitrixRoutingResolver;
use ChatSync\App\Integration\Bitrix\ManagerBitrixBinding;
use ChatSync\App\Integration\Bitrix\ManagerBitrixBindingRepository;

final class PdoManagerBitrixBindingRepository extends AbstractPdoRepository implements ManagerBitrixBindingRepository, BitrixRoutingResolver
{
    public function upsert(ManagerBitrixBinding $binding): void
    {
        $this->execute(
            'INSERT INTO manager_bitrix_bindings (
                id,
                manager_account_id,
                portal_id,
                connector_id,
                line_id,
                operator_user_id,
                is_enabled,
                created_at,
                updated_at
             )
             VALUES (
                :id,
                :manager_account_id,
                :portal_id,
                :connector_id,
                :line_id,
                :operator_user_id,
                :is_enabled,
                :created_at,
                :updated_at
             )
             ON CONFLICT (manager_account_id, portal_id, connector_id) DO UPDATE SET
                line_id = EXCLUDED.line_id,
                operator_user_id = EXCLUDED.operator_user_id,
                is_enabled = EXCLUDED.is_enabled,
                updated_at = EXCLUDED.updated_at',
            [
                'id' => $binding->id,
                'manager_account_id' => $binding->managerAccountId,
                'portal_id' => $binding->portalId,
                'connector_id' => $binding->connectorId,
                'line_id' => $binding->lineId,
                'operator_user_id' => $binding->operatorUserId,
                'is_enabled' => $binding->isEnabled ? 'true' : 'false',
                'created_at' => $binding->createdAt->format(DATE_ATOM),
                'updated_at' => $binding->updatedAt->format(DATE_ATOM),
            ],
        );
    }

    public function resolveForManagerAccount(string $channelProvider, string $managerAccountExternalId): ?BitrixRoutingContext
    {
        $row = $this->execute(
            'SELECT
                b.connector_id,
                b.line_id,
                i.access_token,
                i.expires_at,
                i.rest_base_url
             FROM manager_accounts ma
             INNER JOIN manager_bitrix_bindings b
                 ON b.manager_account_id = ma.id
                AND b.is_enabled = TRUE
             INNER JOIN bitrix_app_installs i
                 ON i.portal_id = b.portal_id
                AND i.active = TRUE
             WHERE ma.channel_provider = :channel_provider
               AND ma.external_account_id = :external_account_id
             ORDER BY b.updated_at DESC
             LIMIT 1',
            [
                'channel_provider' => $channelProvider,
                'external_account_id' => $managerAccountExternalId,
            ],
        )->fetch();

        if ($row === false || !is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return new BitrixRoutingContext(
            restBaseUrl: (string) $row['rest_base_url'],
            connectorId: (string) $row['connector_id'],
            lineId: (string) $row['line_id'],
            accessToken: is_string($row['access_token']) && $row['access_token'] !== '' ? $row['access_token'] : null,
            expiresAt: $this->dateTime((string) $row['expires_at']),
        );
    }
}

