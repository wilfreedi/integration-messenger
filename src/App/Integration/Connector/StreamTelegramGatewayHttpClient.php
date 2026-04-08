<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

use JsonException;
use RuntimeException;

final class StreamTelegramGatewayHttpClient implements TelegramGatewayHttpClient
{
    public function post(string $baseUrl, string $path, string $token, array $payload): array
    {
        $headers = [
            'Content-Type: application/json',
            'Connection: close',
        ];

        if ($token !== '') {
            $headers[] = 'X-Webhook-Token: ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        $endpoint = rtrim($baseUrl, '/') . $path;
        $response = file_get_contents($endpoint, false, $context);

        if ($response === false) {
            throw new RuntimeException('Telegram gateway request failed.');
        }

        $statusCode = $this->statusCode($http_response_header ?? []);
        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf(
                'Telegram gateway returned HTTP %d: %s',
                $statusCode,
                $response,
            ));
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Telegram gateway returned invalid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Telegram gateway response must be a JSON object.');
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

