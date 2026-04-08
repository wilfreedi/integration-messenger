<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

use ChatSync\Shared\Domain\Clock;
use RuntimeException;

final readonly class RefreshingBitrixTokenManager implements BitrixTokenManager
{
    public function __construct(
        private BitrixOAuthClient $oauthClient,
        private BitrixPortalInstallRepository $portalInstallRepository,
        private Clock $clock,
    ) {
    }

    public function ensureValidRoute(BitrixRoutingContext $route, string $managerAccountExternalId): BitrixRoutingContext
    {
        $now = $this->clock->now();

        if ($route->accessToken !== null && $route->expiresAt > $now->modify('+30 seconds')) {
            return $route;
        }

        $refreshToken = $route->refreshToken ?? '';
        $clientId = $route->oauthClientId ?? '';
        $clientSecret = $route->oauthClientSecret ?? '';

        if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
            throw new RuntimeException(sprintf(
                'Bitrix token expired for manager "%s". Save portal with client_id/client_secret in panel to enable auto-refresh.',
                $managerAccountExternalId,
            ));
        }

        $token = $this->oauthClient->refreshToken(
            $this->tokenEndpoint($route->oauthServerEndpoint),
            $clientId,
            $clientSecret,
            $refreshToken,
        );

        $expiresAt = $now->modify(sprintf('+%d seconds', max(60, $token->expiresInSeconds)));
        $newRefreshToken = $token->refreshToken ?? $refreshToken;
        $scope = $token->scope ?? '';

        $this->portalInstallRepository->updateTokens(
            portalDomain: $route->portalDomain,
            accessToken: $token->accessToken,
            refreshToken: $newRefreshToken,
            expiresAt: $expiresAt,
            scope: $scope,
        );

        return new BitrixRoutingContext(
            portalDomain: $route->portalDomain,
            restBaseUrl: $route->restBaseUrl,
            connectorId: $route->connectorId,
            lineId: $route->lineId,
            accessToken: $token->accessToken,
            refreshToken: $newRefreshToken,
            oauthClientId: $route->oauthClientId,
            oauthClientSecret: $route->oauthClientSecret,
            oauthServerEndpoint: $route->oauthServerEndpoint,
            expiresAt: $expiresAt,
        );
    }

    private function tokenEndpoint(?string $serverEndpoint): string
    {
        $normalized = trim((string) $serverEndpoint);
        if ($normalized === '') {
            return 'https://oauth.bitrix.info/oauth/token/';
        }

        $parts = parse_url($normalized);
        if (!is_array($parts)) {
            throw new RuntimeException('Bitrix OAuth server endpoint is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $user = (string) ($parts['user'] ?? '');
        $pass = (string) ($parts['pass'] ?? '');
        $query = (string) ($parts['query'] ?? '');
        $fragment = (string) ($parts['fragment'] ?? '');

        if ($scheme !== 'https' || $host === '') {
            throw new RuntimeException('Bitrix OAuth server endpoint must be HTTPS URL.');
        }
        if ($user !== '' || $pass !== '' || $query !== '' || $fragment !== '') {
            throw new RuntimeException('Bitrix OAuth server endpoint must not contain auth/query/fragment.');
        }

        if ($path === '') {
            $path = '/oauth/token';
        } elseif (str_ends_with($path, '/oauth/token')) {
            // no-op
        } elseif (str_ends_with($path, '/rest')) {
            $path = substr($path, 0, -strlen('/rest')) . '/oauth/token';
        } else {
            $path .= '/oauth/token';
        }

        return sprintf('%s://%s/%s/', $scheme, $host, trim($path, '/'));
    }
}
