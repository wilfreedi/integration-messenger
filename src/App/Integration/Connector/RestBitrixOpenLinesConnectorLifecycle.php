<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

use RuntimeException;

final readonly class RestBitrixOpenLinesConnectorLifecycle implements BitrixOpenLinesConnectorLifecycle
{
    public function __construct(
        private BitrixRestClient $restClient,
        private string $placementHandlerUrl,
        private string $webhookUrl,
    ) {
    }

    public function ensure(string $baseUrl, string $connectorId, string $lineId, ?string $authToken): void
    {
        $status = $this->status($baseUrl, $connectorId, $lineId, $authToken);
        if (($status['ok'] ?? false) !== true) {
            $this->register($baseUrl, $connectorId, $authToken);
            $this->activate($baseUrl, $connectorId, $lineId, $authToken);
        }

        // Keep connector endpoints in sync with current deployment URL/token.
        $this->setConnectorData($baseUrl, $connectorId, $lineId, $authToken);
        $this->ensureMessageEventBinding($baseUrl, $authToken);

        $status = $this->status($baseUrl, $connectorId, $lineId, $authToken);
        if (($status['ok'] ?? false) !== true) {
            $details = is_string($status['details'] ?? null) ? $status['details'] : 'unknown status';
            throw new RuntimeException('Bitrix connector is not ready: ' . $details);
        }
    }

    /**
     * @return array{ok: bool, details: string}
     */
    private function status(string $baseUrl, string $connectorId, string $lineId, ?string $authToken): array
    {
        try {
            $response = $this->restClient->call(
                $baseUrl,
                'imconnector.status',
                $this->withAuth([
                    'CONNECTOR' => $connectorId,
                    'LINE' => $lineId,
                ], $authToken),
            );
        } catch (RuntimeException $exception) {
            return [
                'ok' => false,
                'details' => $exception->getMessage(),
            ];
        }

        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            return [
                'ok' => false,
                'details' => 'imconnector.status returned invalid payload.',
            ];
        }

        $configured = $this->toBool($result['CONFIGURED'] ?? null);
        $active = $this->toBool($result['ACTIVE'] ?? ($result['STATUS'] ?? null));
        if ($configured && $active) {
            return [
                'ok' => true,
                'details' => 'configured and active',
            ];
        }

        return [
            'ok' => false,
            'details' => sprintf(
                'CONFIGURED=%s, ACTIVE=%s',
                $configured ? 'Y' : 'N',
                $active ? 'Y' : 'N',
            ),
        ];
    }

    private function register(string $baseUrl, string $connectorId, ?string $authToken): void
    {
        try {
            $response = $this->restClient->call(
                $baseUrl,
                'imconnector.register',
                $this->withAuth([
                    'ID' => $connectorId,
                    'NAME' => 'Chat Sync',
                    'ICON' => ['DATA_IMAGE' => $this->iconDataUri('#0f5ec7')],
                    'ICON_DISABLED' => ['DATA_IMAGE' => $this->iconDataUri('#94a3b8')],
                    'PLACEMENT_HANDLER' => $this->placementHandlerUrl,
                ], $authToken),
            );

            if (($response['result'] ?? true) === false) {
                throw new RuntimeException('imconnector.register returned result=false');
            }
        } catch (RuntimeException $exception) {
            $message = strtolower($exception->getMessage());
            if (
                str_contains($message, 'already')
                || str_contains($message, 'существует')
                || str_contains($message, 'registered')
            ) {
                return;
            }

            throw $exception;
        }
    }

    private function activate(string $baseUrl, string $connectorId, string $lineId, ?string $authToken): void
    {
        $response = $this->restClient->call(
            $baseUrl,
            'imconnector.activate',
            $this->withAuth([
                'CONNECTOR' => $connectorId,
                'LINE' => $lineId,
                'ACTIVE' => '1',
            ], $authToken),
        );

        if (($response['result'] ?? true) === false) {
            throw new RuntimeException('imconnector.activate returned result=false');
        }
    }

    private function setConnectorData(string $baseUrl, string $connectorId, string $lineId, ?string $authToken): void
    {
        $dataId = sprintf('%s_line%s', $connectorId, $lineId);
        $displayName = sprintf('Chat Sync Line %s', $lineId);

        $response = $this->restClient->call(
            $baseUrl,
            'imconnector.connector.data.set',
            $this->withAuth([
                'CONNECTOR' => $connectorId,
                'LINE' => ctype_digit($lineId) ? (int) $lineId : $lineId,
                'DATA' => [
                    'ID' => $dataId,
                    'URL' => $this->webhookUrl,
                    'URL_IM' => $this->webhookUrl,
                    'NAME' => $displayName,
                ],
            ], $authToken),
        );

        if (($response['result'] ?? true) === false) {
            throw new RuntimeException('imconnector.connector.data.set returned result=false');
        }
    }

    private function ensureMessageEventBinding(string $baseUrl, ?string $authToken): void
    {
        if ($this->webhookUrl === '') {
            return;
        }

        $eventName = 'OnImConnectorMessageAdd';
        $eventsResult = null;

        try {
            $eventsResponse = $this->restClient->call(
                $baseUrl,
                'event.get',
                $this->withAuth([], $authToken),
            );
            $eventsResult = $eventsResponse['result'] ?? null;
        } catch (RuntimeException) {
            return;
        }

        if (!is_array($eventsResult)) {
            return;
        }

        if ($this->hasEventBinding($eventsResult, $eventName, $this->webhookUrl)) {
            return;
        }

        try {
            $this->restClient->call(
                $baseUrl,
                'event.bind',
                $this->withAuth([
                    'EVENT_NAME' => $eventName,
                    'HANDLER' => $this->webhookUrl,
                ], $authToken),
            );
        } catch (RuntimeException $firstException) {
            try {
                $this->restClient->call(
                    $baseUrl,
                    'event.bind',
                    $this->withAuth([
                        'event' => $eventName,
                        'handler' => $this->webhookUrl,
                    ], $authToken),
                );
            } catch (RuntimeException) {
                return;
            }
        }
    }

    /**
     * @param array<int|string, mixed> $events
     */
    private function hasEventBinding(array $events, string $eventName, string $handlerUrl): bool
    {
        foreach ($events as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entryEvent = $entry['EVENT_NAME'] ?? $entry['event'] ?? null;
            $entryHandler = $entry['HANDLER'] ?? $entry['handler'] ?? null;
            if (
                is_string($entryEvent)
                && is_string($entryHandler)
                && strcasecmp($entryEvent, $eventName) === 0
                && trim($entryHandler) === $handlerUrl
            ) {
                return true;
            }

            if ($this->hasEventBinding($entry, $eventName, $handlerUrl)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withAuth(array $payload, ?string $token): array
    {
        if ($token === null || $token === '') {
            return $payload;
        }

        $payload['auth'] = $token;

        return $payload;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 't', 'yes', 'y', 'on', 'y'], true);
        }

        return false;
    }

    private function iconDataUri(string $fillColor): string
    {
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" rx="14" fill="%s"/><path fill="#fff" d="M19 20h26v6H27v4h16v6H27v8h-8V20z"/></svg>',
            $fillColor,
        );

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
