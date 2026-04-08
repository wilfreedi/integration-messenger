<?php

declare(strict_types=1);

namespace ChatSync\App\Http;

use JsonException;
use RuntimeException;

final class Json
{
    /**
     * @return array<string, mixed>
     */
    public function decodeRequestBody(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Request body must be valid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('JSON body must be an object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function respond(array $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}

