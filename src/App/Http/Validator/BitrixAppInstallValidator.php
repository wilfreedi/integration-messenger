<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Validator;

use ChatSync\App\Integration\Bitrix\RegisterBitrixPortalInstallCommand;
use InvalidArgumentException;

final class BitrixAppInstallValidator
{
    /**
     * @param array<string, mixed> $payload
     */
    public function validate(array $payload): RegisterBitrixPortalInstallCommand
    {
        $auth = $payload['auth'] ?? $payload;

        if (!is_array($auth)) {
            throw new InvalidArgumentException('Bitrix install payload must contain auth object.');
        }

        $portalDomain = $this->portalDomain($auth);
        $clientEndpoint = $this->optionalString($auth, 'client_endpoint');
        $restBaseUrl = $this->restBaseUrl($portalDomain, $clientEndpoint);

        return new RegisterBitrixPortalInstallCommand(
            portalDomain: $portalDomain,
            memberId: $this->optionalString($auth, 'member_id'),
            accessToken: $this->requiredString($auth, 'access_token'),
            refreshToken: $this->requiredString($auth, 'refresh_token'),
            expiresInSeconds: $this->expiresIn($auth),
            scope: $this->optionalString($auth, 'scope') ?? '',
            applicationToken: $this->requiredString($auth, 'application_token'),
            restBaseUrl: $restBaseUrl,
            oauthClientId: $this->firstOptionalString($auth, ['client_id', 'application_client_id']),
            oauthClientSecret: $this->firstOptionalString($auth, ['client_secret', 'application_client_secret']),
            oauthServerEndpoint: $this->oauthServerEndpoint($auth),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function expiresIn(array $payload): int
    {
        $candidates = ['expires', 'expires_in'];

        foreach ($candidates as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) && $value > 0) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value) && $value !== '0') {
                return (int) $value;
            }
        }

        return 3600;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" is required and must be a non-empty string.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a string or null.', $key));
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function firstOptionalString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->optionalString($payload, $key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function portalDomain(array $payload): string
    {
        $value = strtolower($this->requiredString($payload, 'domain'));

        if (preg_match('/^[a-z0-9.-]+$/', $value) !== 1 || !str_contains($value, '.')) {
            throw new InvalidArgumentException('Field "domain" must be a valid Bitrix portal domain.');
        }

        return $value;
    }

    private function restBaseUrl(string $portalDomain, ?string $clientEndpoint): string
    {
        if ($clientEndpoint === null) {
            return sprintf('https://%s/rest', $portalDomain);
        }

        $parts = parse_url($clientEndpoint);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('Field "client_endpoint" must be a valid URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $user = (string) ($parts['user'] ?? '');
        $pass = (string) ($parts['pass'] ?? '');
        $query = (string) ($parts['query'] ?? '');
        $fragment = (string) ($parts['fragment'] ?? '');

        if ($scheme !== 'https' || $host === '') {
            throw new InvalidArgumentException('Field "client_endpoint" must be an HTTPS URL.');
        }
        if ($user !== '' || $pass !== '' || $query !== '' || $fragment !== '') {
            throw new InvalidArgumentException('Field "client_endpoint" must not include auth credentials, query, or fragment.');
        }
        if ($host !== $portalDomain) {
            throw new InvalidArgumentException('Field "client_endpoint" host must match auth.domain.');
        }
        if ($path === '' || !str_starts_with($path, '/rest')) {
            throw new InvalidArgumentException('Field "client_endpoint" path must start with "/rest".');
        }

        return rtrim(sprintf('%s://%s%s', $scheme, $host, $path), '/');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function oauthServerEndpoint(array $payload): ?string
    {
        $value = $this->optionalString($payload, 'server_endpoint');
        if ($value === null) {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('Field "server_endpoint" must be a valid URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $user = (string) ($parts['user'] ?? '');
        $pass = (string) ($parts['pass'] ?? '');
        $query = (string) ($parts['query'] ?? '');
        $fragment = (string) ($parts['fragment'] ?? '');

        if ($scheme !== 'https' || $host === '') {
            throw new InvalidArgumentException('Field "server_endpoint" must be an HTTPS URL.');
        }
        if ($user !== '' || $pass !== '' || $query !== '' || $fragment !== '') {
            throw new InvalidArgumentException('Field "server_endpoint" must not include auth credentials, query, or fragment.');
        }

        return rtrim(sprintf('%s://%s%s', $scheme, $host, $path), '/');
    }
}
