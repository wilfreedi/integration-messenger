<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

use JsonException;
use RuntimeException;

final class StreamBitrixRestClient implements BitrixRestClient
{
    public function call(string $baseUrl, string $method, array $payload): array
    {
        $endpoint = sprintf('%s/%s.json', rtrim($baseUrl, '/'), $method);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Connection: close',
                ]),
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);

        $response = file_get_contents($endpoint, false, $context);
        if ($response === false) {
            throw new RuntimeException(sprintf('Bitrix REST request failed for method "%s".', $method));
        }

        $statusCode = $this->statusCode($http_response_header ?? []);
        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf(
                'Bitrix REST method "%s" returned HTTP %d: %s',
                $method,
                $statusCode,
                $response,
            ));
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Bitrix REST method "%s" returned invalid JSON.', $method), previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Bitrix REST method "%s" must return a JSON object.', $method));
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            $errorDescription = is_string($decoded['error_description'] ?? null)
                ? $decoded['error_description']
                : '';

            throw new RuntimeException(sprintf(
                'Bitrix REST method "%s" failed: %s %s',
                $method,
                $decoded['error'],
                trim($errorDescription),
            ));
        }

        return $decoded;
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

