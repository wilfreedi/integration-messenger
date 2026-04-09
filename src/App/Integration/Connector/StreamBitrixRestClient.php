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
        $debug = $this->isDebugEnabled();
        if ($debug) {
            $this->debugLog('request', $method, [
                'endpoint' => $endpoint,
                'payload' => $this->redact($payload),
            ]);
        }

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
            if ($debug) {
                $this->debugLog('transport_failed', $method, [
                    'endpoint' => $endpoint,
                ]);
            }
            throw new RuntimeException(sprintf('Bitrix REST request failed for method "%s".', $method));
        }

        $statusCode = $this->statusCode($http_response_header ?? []);
        if ($debug) {
            $this->debugLog('response', $method, [
                'status' => $statusCode,
                'body_preview' => $this->truncate($response),
            ]);
        }
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

    private function isDebugEnabled(): bool
    {
        $raw = getenv('APP_DEBUG');
        if ($raw === false) {
            return false;
        }

        $normalized = strtolower(trim((string) $raw));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function debugLog(string $stage, string $method, array $context): void
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }

        error_log(sprintf('[bitrix_rest] %s %s %s', $stage, $method, $json));
    }

    private function truncate(string $value, int $maxLen = 1500): string
    {
        if (strlen($value) <= $maxLen) {
            return $value;
        }

        return substr($value, 0, $maxLen) . '...';
    }

    private function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $keyString = is_string($key) ? strtolower($key) : (string) $key;
                if (
                    str_contains($keyString, 'token')
                    || str_contains($keyString, 'secret')
                    || $keyString === 'auth'
                    || $keyString === 'authorization'
                ) {
                    $result[$key] = '***';
                    continue;
                }
                $result[$key] = $this->redact($item);
            }

            return $result;
        }

        return $value;
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
