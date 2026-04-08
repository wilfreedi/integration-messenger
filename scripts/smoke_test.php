<?php

declare(strict_types=1);

use ChatSync\App\Bootstrap\ApplicationContainer;

/** @var ApplicationContainer $container */
$container = require __DIR__ . '/bootstrap.php';

$baseUrl = sprintf('http://127.0.0.1:%d', $container->config()->appPort);
$suffix = bin2hex(random_bytes(4));

$health = request('GET', $baseUrl . '/health');

if (($health['status'] ?? '') !== 'ok') {
    fwrite(STDERR, "Health check failed.\n");
    exit(1);
}

$inboundPayload = [
    'event_id' => sprintf('smoke-telegram-event-%s', $suffix),
    'channel_provider' => 'telegram',
    'crm_provider' => 'bitrix',
    'manager_account_external_id' => 'telegram-manager-account',
    'contact_external_chat_id' => sprintf('telegram-chat-%s', $suffix),
    'contact_external_user_id' => sprintf('telegram-user-%s', $suffix),
    'contact_display_name' => sprintf('Smoke Contact %s', $suffix),
    'external_message_id' => sprintf('telegram-message-smoke-%s', $suffix),
    'body' => 'Smoke test inbound message',
    'occurred_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'attachments' => [
        [
            'type' => 'photo',
            'external_file_id' => 'smoke-file-1',
            'file_name' => 'smoke.jpg',
            'mime_type' => 'image/jpeg',
        ],
    ],
];

$inboundResponse = request('POST', $baseUrl . '/api/webhooks/channel-message', $inboundPayload);

$threadRow = $container->pdo()->query('SELECT external_thread_id FROM crm_threads ORDER BY created_at DESC LIMIT 1')->fetch();

if ($threadRow === false) {
    fwrite(STDERR, "CRM thread was not created.\n");
    exit(1);
}

$outboundPayload = [
    'event_id' => sprintf('smoke-bitrix-event-%s', $suffix),
    'crm_provider' => 'bitrix',
    'channel_provider' => 'telegram',
    'external_thread_id' => $threadRow['external_thread_id'],
    'external_message_id' => sprintf('bitrix-message-smoke-%s', $suffix),
    'body' => 'Smoke test outbound reply',
    'occurred_at' => (new DateTimeImmutable())->format(DATE_ATOM),
];

$outboundResponse = request('POST', $baseUrl . '/api/webhooks/crm-message', $outboundPayload);
$state = request('GET', $baseUrl . '/api/debug/state');

echo json_encode([
    'health' => $health,
    'inbound' => $inboundResponse,
    'outbound' => $outboundResponse,
    'summary' => [
        'contacts' => count($state['contacts'] ?? []),
        'conversations' => count($state['conversations'] ?? []),
        'crm_threads' => count($state['crm_threads'] ?? []),
        'messages' => count($state['messages'] ?? []),
        'deliveries' => count($state['deliveries'] ?? []),
        'processed_events' => count($state['processed_events'] ?? []),
        'audit_logs' => count($state['audit_logs'] ?? []),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;

/**
 * @param array<string, mixed>|null $payload
 * @return array<string, mixed>
 */
function request(string $method, string $url, ?array $payload = null): array
{
    $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Connection: close',
            ]),
            'content' => $body,
            'ignore_errors' => true,
        ],
    ]);

    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        throw new RuntimeException(sprintf('HTTP %s %s failed.', $method, $url));
    }

    $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $statusCode = isset($matches[1]) ? (int) $matches[1] : 500;

    if ($statusCode >= 400) {
        throw new RuntimeException(sprintf('HTTP %s %s failed with status %d: %s', $method, $url, $statusCode, $response));
    }

    $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('HTTP %s %s did not return a JSON object.', $method, $url));
    }

    return $decoded;
}
