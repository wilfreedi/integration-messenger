<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\App\Integration\Bitrix\BitrixPortalInstall;
use ChatSync\App\Integration\Bitrix\BitrixPortalInstallRepository;
use Throwable;

final class PdoBitrixPortalInstallRepository extends AbstractPdoRepository implements BitrixPortalInstallRepository
{
    public function upsert(BitrixPortalInstall $install): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->execute(
                'INSERT INTO bitrix_portals (id, portal_domain, member_id, app_status, created_at, updated_at)
                 VALUES (:id, :portal_domain, :member_id, :app_status, :created_at, :updated_at)
                 ON CONFLICT (portal_domain) DO UPDATE SET
                    member_id = EXCLUDED.member_id,
                    app_status = EXCLUDED.app_status,
                    updated_at = EXCLUDED.updated_at',
                [
                    'id' => $install->portalId,
                    'portal_domain' => $install->portalDomain,
                    'member_id' => $install->memberId,
                    'app_status' => $install->appStatus,
                    'created_at' => $install->createdAt->format(DATE_ATOM),
                    'updated_at' => $install->updatedAt->format(DATE_ATOM),
                ],
            );

            $portalId = $this->portalIdByDomain($install->portalDomain);

            $this->execute(
                'INSERT INTO bitrix_app_installs (
                    id,
                    portal_id,
                    access_token,
                    refresh_token,
                    expires_at,
                    scope,
                    application_token,
                    rest_base_url,
                    active,
                    created_at,
                    updated_at
                 )
                 VALUES (
                    :id,
                    :portal_id,
                    :access_token,
                    :refresh_token,
                    :expires_at,
                    :scope,
                    :application_token,
                    :rest_base_url,
                    :active,
                    :created_at,
                    :updated_at
                 )
                 ON CONFLICT (portal_id) DO UPDATE SET
                    access_token = EXCLUDED.access_token,
                    refresh_token = EXCLUDED.refresh_token,
                    expires_at = EXCLUDED.expires_at,
                    scope = EXCLUDED.scope,
                    application_token = EXCLUDED.application_token,
                    rest_base_url = EXCLUDED.rest_base_url,
                    active = EXCLUDED.active,
                    updated_at = EXCLUDED.updated_at',
                [
                    'id' => $install->installId,
                    'portal_id' => $portalId,
                    'access_token' => $install->accessToken,
                    'refresh_token' => $install->refreshToken,
                    'expires_at' => $install->expiresAt->format(DATE_ATOM),
                    'scope' => $install->scope,
                    'application_token' => $install->applicationToken,
                    'rest_base_url' => $install->restBaseUrl,
                    'active' => $install->active ? 'true' : 'false',
                    'created_at' => $install->createdAt->format(DATE_ATOM),
                    'updated_at' => $install->updatedAt->format(DATE_ATOM),
                ],
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function findByPortalDomain(string $portalDomain): ?BitrixPortalInstall
    {
        $row = $this->execute(
            'SELECT
                p.id AS portal_id,
                p.portal_domain,
                p.member_id,
                p.app_status,
                p.created_at AS portal_created_at,
                p.updated_at AS portal_updated_at,
                i.id AS install_id,
                i.access_token,
                i.refresh_token,
                i.expires_at,
                i.scope,
                i.application_token,
                i.rest_base_url,
                i.active,
                i.created_at AS install_created_at,
                i.updated_at AS install_updated_at
             FROM bitrix_portals p
             INNER JOIN bitrix_app_installs i ON i.portal_id = p.id
             WHERE p.portal_domain = :portal_domain
             LIMIT 1',
            ['portal_domain' => $portalDomain],
        )->fetch();

        if ($row === false || !is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $this->map($row);
    }

    private function portalIdByDomain(string $portalDomain): string
    {
        $value = $this->execute(
            'SELECT id FROM bitrix_portals WHERE portal_domain = :portal_domain LIMIT 1',
            ['portal_domain' => $portalDomain],
        )->fetchColumn();

        if (!is_string($value) || $value === '') {
            throw new \RuntimeException(sprintf('Bitrix portal id not found for domain "%s".', $portalDomain));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): BitrixPortalInstall
    {
        return new BitrixPortalInstall(
            portalId: (string) $row['portal_id'],
            installId: (string) $row['install_id'],
            portalDomain: (string) $row['portal_domain'],
            memberId: isset($row['member_id']) && is_string($row['member_id']) && $row['member_id'] !== '' ? $row['member_id'] : null,
            appStatus: (string) $row['app_status'],
            accessToken: (string) $row['access_token'],
            refreshToken: (string) $row['refresh_token'],
            expiresAt: $this->dateTime((string) $row['expires_at']),
            scope: (string) $row['scope'],
            applicationToken: (string) $row['application_token'],
            restBaseUrl: (string) $row['rest_base_url'],
            active: (bool) $row['active'],
            createdAt: $this->dateTime((string) $row['install_created_at']),
            updatedAt: $this->dateTime((string) $row['install_updated_at']),
        );
    }
}

