<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

interface TelegramGatewayHttpClient
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(string $baseUrl, string $path, string $token, array $payload): array;
}

