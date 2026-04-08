<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

use JsonException;
use RuntimeException;

final class StreamBitrixOAuthClient implements BitrixOAuthClient
{
    public function refreshToken(
        string $tokenEndpoint,
        string $clientId,
        string $clientSecret,
        string $refreshToken
    ): BitrixOAuthToken {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Connection: close',
                ]),
                'content' => http_build_query([
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                ], '', '&', PHP_QUERY_RFC3986),
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);

        $response = file_get_contents($tokenEndpoint, false, $context);
        if ($response === false) {
            throw new RuntimeException('Bitrix OAuth refresh request failed.');
        }

        $statusCode = $this->statusCode($http_response_header ?? []);
        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf(
                'Bitrix OAuth refresh returned HTTP %d: %s',
                $statusCode,
                $response,
            ));
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Bitrix OAuth refresh returned invalid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Bitrix OAuth refresh response must be JSON object.');
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            $description = is_string($decoded['error_description'] ?? null)
                ? trim($decoded['error_description'])
                : '';

            throw new RuntimeException(sprintf(
                'Bitrix OAuth refresh failed: %s %s',
                $decoded['error'],
                $description,
            ));
        }

        $accessToken = $decoded['access_token'] ?? null;
        if (!is_string($accessToken) || trim($accessToken) === '') {
            throw new RuntimeException('Bitrix OAuth refresh response does not contain "access_token".');
        }

        $refreshTokenValue = $decoded['refresh_token'] ?? null;
        $refreshTokenOut = is_string($refreshTokenValue) && trim($refreshTokenValue) !== ''
            ? trim($refreshTokenValue)
            : null;

        $expiresIn = $this->expiresInSeconds($decoded);
        $scope = is_string($decoded['scope'] ?? null) && trim($decoded['scope']) !== ''
            ? trim($decoded['scope'])
            : null;

        return new BitrixOAuthToken(
            accessToken: trim($accessToken),
            refreshToken: $refreshTokenOut,
            expiresInSeconds: $expiresIn,
            scope: $scope,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function expiresInSeconds(array $payload): int
    {
        foreach (['expires', 'expires_in'] as $key) {
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
     * @param array<int, string> $responseHeaders
     */
    private function statusCode(array $responseHeaders): int
    {
        $statusLine = $responseHeaders[0] ?? 'HTTP/1.1 500';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);

        return isset($matches[1]) ? (int) $matches[1] : 500;
    }
}

