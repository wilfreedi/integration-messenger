<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use InvalidArgumentException;
use RuntimeException;

final readonly class BitrixTelegramGatewayAccountsController
{
    public function __construct(
        private string $telegramGatewayBaseUrl = '',
        private string $telegramGatewayToken = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        $accounts = $this->fetchAccounts();

        return [
            'status' => 'ok',
            'count' => count($accounts),
            'items' => $accounts,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function rebind(array $payload): array
    {
        $targetManagerId = $this->requiredString($payload, 'manager_account_external_id');
        $accountId = $this->optionalString($payload, 'account_id');

        $accounts = $this->fetchAccounts();
        if ($accounts === []) {
            throw new InvalidArgumentException('В telegram-gateway нет аккаунтов. Сначала авторизуй Telegram.');
        }

        $selected = null;

        if ($accountId !== null) {
            foreach ($accounts as $item) {
                if (($item['account_id'] ?? '') === $accountId) {
                    $selected = $item;
                    break;
                }
            }

            if ($selected === null) {
                throw new InvalidArgumentException(sprintf('Telegram аккаунт "%s" не найден.', $accountId));
            }
        } elseif (count($accounts) === 1) {
            $selected = $accounts[0];
        } else {
            foreach ($accounts as $item) {
                if (($item['manager_account_external_id'] ?? '') === $targetManagerId) {
                    return [
                        'status' => 'ok',
                        'message' => 'Telegram аккаунт уже привязан к этому менеджеру.',
                        'account_before' => $item,
                        'account_after' => $item,
                    ];
                }
            }

            throw new InvalidArgumentException(
                'Найдено несколько Telegram аккаунтов. Укажи account_id явно.',
            );
        }

        $before = $selected;
        if (($before['manager_account_external_id'] ?? '') === $targetManagerId) {
            return [
                'status' => 'ok',
                'message' => 'Telegram аккаунт уже привязан к этому менеджеру.',
                'account_before' => $before,
                'account_after' => $before,
            ];
        }

        $response = $this->request(
            'POST',
            '/v1/accounts/' . rawurlencode((string) ($before['account_id'] ?? '')) . '/manager',
            ['manager_account_external_id' => $targetManagerId],
        );

        $updated = $response['account'] ?? null;
        if (!is_array($updated)) {
            throw new RuntimeException('telegram-gateway вернул некорректный ответ при перепривязке аккаунта.');
        }

        return [
            'status' => 'ok',
            'message' => 'Telegram аккаунт перепривязан к выбранному менеджеру.',
            'account_before' => $before,
            'account_after' => $this->normalizeAccount($updated),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAccounts(): array
    {
        $response = $this->request('GET', '/v1/accounts');
        $rawItems = $response['accounts'] ?? null;
        if (!is_array($rawItems)) {
            throw new RuntimeException('В ответе telegram-gateway отсутствует поле accounts.');
        }

        $items = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = $this->normalizeAccount($item);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeAccount(array $item): array
    {
        return [
            'account_id' => $this->stringOrEmpty($item['account_id'] ?? null),
            'manager_account_external_id' => $this->stringOrEmpty($item['manager_account_external_id'] ?? null),
            'authorization_state' => $this->stringOrEmpty($item['authorization_state'] ?? null),
            'phone_number' => $this->stringOrEmpty($item['phone_number'] ?? null),
            'configured' => (bool) ($item['configured'] ?? false),
            'last_dispatch_status' => $this->stringOrEmpty($item['last_dispatch_status'] ?? null),
            'last_error' => $this->stringOrEmpty($item['last_error'] ?? null),
            'created_at' => $this->stringOrEmpty($item['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Поле "%s" обязательно.', $field));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $field): ?string
    {
        $value = $payload[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Поле "%s" должно быть строкой.', $field));
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $baseUrl = rtrim($this->telegramGatewayBaseUrl, '/');
        if ($baseUrl === '') {
            throw new RuntimeException('TELEGRAM_GATEWAY_BASE_URL не задан.');
        }

        $headers = ['Accept: application/json'];
        $content = null;
        if ($payload !== null) {
            $content = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($content)) {
                throw new RuntimeException('Не удалось сериализовать payload для telegram-gateway.');
            }
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($content);
        }
        if ($this->telegramGatewayToken !== '') {
            $headers[] = 'X-Integration-Token: ' . $this->telegramGatewayToken;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content ?? '',
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $url = $baseUrl . $path;
        $raw = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = $this->statusCode($responseHeaders);

        if ($raw === false && $statusCode === 0) {
            throw new RuntimeException(sprintf('Не удалось выполнить запрос к telegram-gateway: %s', $url));
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new RuntimeException('telegram-gateway вернул некорректный JSON.');
        }

        if ($statusCode >= 400) {
            $message = $decoded['message'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                throw new InvalidArgumentException(trim($message));
            }
            throw new RuntimeException(sprintf('telegram-gateway вернул HTTP %d.', $statusCode));
        }

        return $decoded;
    }

    /**
     * @param list<string> $headers
     */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }
            if (!str_starts_with($header, 'HTTP/')) {
                continue;
            }
            if (preg_match('/\s(\d{3})\s?/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}

