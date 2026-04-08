<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Query\IntegrationLogsQuery;

final readonly class BitrixLogsController
{
    public function __construct(
        private IntegrationLogsQuery $query,
        private string $telegramGatewayBaseUrl = '',
        private string $telegramGatewayToken = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(int $limit = 200): array
    {
        $auditLogs = $this->query->auditLogs($limit);
        $gateway = $this->telegramGatewayEvents();

        return [
            'status' => 'ok',
            'summary' => $this->summary($auditLogs, $gateway['events']),
            'audit_logs' => $auditLogs,
            'telegram_gateway_events' => $gateway['events'],
            'telegram_gateway_status' => $gateway['status'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clear(): array
    {
        $deletedAuditRows = $this->query->clearAuditLogs();
        $gatewayClear = $this->clearTelegramGatewayEvents();

        return [
            'status' => 'ok',
            'deleted_audit_rows' => $deletedAuditRows,
            'telegram_gateway_clear' => $gatewayClear,
        ];
    }

    /**
     * @return array{status: array<string, mixed>, events: list<array<string, mixed>>}
     */
    private function telegramGatewayEvents(): array
    {
        $baseUrl = rtrim($this->telegramGatewayBaseUrl, '/');
        if ($baseUrl === '') {
            return [
                'status' => [
                    'status' => 'skipped',
                    'ok' => true,
                    'message' => 'TELEGRAM_GATEWAY_BASE_URL не задан.',
                ],
                'events' => [],
            ];
        }

        $endpoint = $baseUrl . '/v1/debug/events';
        $headers = ["Accept: application/json"];
        if ($this->telegramGatewayToken !== '') {
            $headers[] = 'X-Integration-Token: ' . $this->telegramGatewayToken;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 8,
            ],
        ]);

        $raw = @file_get_contents($endpoint, false, $context);
        if ($raw === false) {
            return [
                'status' => [
                    'status' => 'failed',
                    'ok' => false,
                    'message' => 'Не удалось получить события telegram-gateway.',
                    'endpoint' => $endpoint,
                ],
                'events' => [],
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'status' => [
                    'status' => 'failed',
                    'ok' => false,
                    'message' => 'telegram-gateway вернул некорректный JSON.',
                    'endpoint' => $endpoint,
                ],
                'events' => [],
            ];
        }

        $eventsRaw = $decoded['events'] ?? null;
        if (!is_array($eventsRaw)) {
            return [
                'status' => [
                    'status' => 'failed',
                    'ok' => false,
                    'message' => 'В ответе telegram-gateway нет поля events.',
                    'endpoint' => $endpoint,
                ],
                'events' => [],
            ];
        }

        $events = [];
        foreach ($eventsRaw as $event) {
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        return [
            'status' => [
                'status' => 'ok',
                'ok' => true,
                'message' => 'События telegram-gateway получены.',
                'endpoint' => $endpoint,
                'events_count' => count($events),
            ],
            'events' => $events,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clearTelegramGatewayEvents(): array
    {
        $baseUrl = rtrim($this->telegramGatewayBaseUrl, '/');
        if ($baseUrl === '') {
            return [
                'status' => 'skipped',
                'ok' => true,
                'message' => 'TELEGRAM_GATEWAY_BASE_URL не задан.',
            ];
        }

        $endpoint = $baseUrl . '/v1/debug/events/clear';
        $headers = ["Content-Type: application/json", "Accept: application/json"];
        if ($this->telegramGatewayToken !== '') {
            $headers[] = 'X-Integration-Token: ' . $this->telegramGatewayToken;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => '{}',
                'ignore_errors' => true,
                'timeout' => 8,
            ],
        ]);

        $raw = @file_get_contents($endpoint, false, $context);
        if ($raw === false) {
            return [
                'status' => 'failed',
                'ok' => false,
                'message' => 'Не удалось очистить события telegram-gateway.',
                'endpoint' => $endpoint,
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'status' => 'failed',
                'ok' => false,
                'message' => 'telegram-gateway вернул некорректный JSON при очистке событий.',
                'endpoint' => $endpoint,
            ];
        }

        $decoded['endpoint'] = $endpoint;

        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $auditLogs
     * @param list<array<string, mixed>> $gatewayEvents
     * @return array<string, mixed>
     */
    private function summary(array $auditLogs, array $gatewayEvents): array
    {
        $telegramInboundReceived = 0;
        $bitrixSendSuccess = 0;
        $bitrixSendFailed = 0;
        $lastBitrixError = null;

        foreach ($auditLogs as $item) {
            $provider = (string) ($item['provider'] ?? '');
            $operation = (string) ($item['operation'] ?? '');
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];

            if ($provider === 'telegram' && $operation === 'webhook_received') {
                $telegramInboundReceived++;
            }

            if ($provider === 'bitrix' && $operation === 'send_message') {
                $bitrixSendSuccess++;
            }

            if ($provider === 'bitrix' && $operation === 'send_message_failed') {
                $bitrixSendFailed++;
                if ($lastBitrixError === null && is_string($payload['error'] ?? null)) {
                    $lastBitrixError = $payload['error'];
                }
            }
        }

        $gatewayInbound = 0;
        $gatewayOutbound = 0;
        foreach ($gatewayEvents as $event) {
            $direction = (string) ($event['direction'] ?? '');
            if ($direction === 'inbound') {
                $gatewayInbound++;
            }
            if ($direction === 'outbound') {
                $gatewayOutbound++;
            }
        }

        return [
            'telegram_webhook_received_total' => $telegramInboundReceived,
            'bitrix_send_success_total' => $bitrixSendSuccess,
            'bitrix_send_failed_total' => $bitrixSendFailed,
            'telegram_gateway_inbound_total' => $gatewayInbound,
            'telegram_gateway_outbound_total' => $gatewayOutbound,
            'last_bitrix_error' => $lastBitrixError,
        ];
    }
}
