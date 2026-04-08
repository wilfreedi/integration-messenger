<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

interface BitrixRestClient
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function call(string $baseUrl, string $method, array $payload): array;
}

