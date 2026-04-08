<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Integration\Bitrix\BitrixPortalInstall;
use ChatSync\App\Integration\Bitrix\BitrixPortalInstallRepository;
use ChatSync\App\Integration\Bitrix\BitrixRoutingContext;
use ChatSync\App\Integration\Bitrix\BitrixRoutingResolver;
use ChatSync\App\Integration\Bitrix\BitrixTokenManager;
use ChatSync\App\Integration\Connector\BitrixOpenLinesConnectorLifecycle;
use ChatSync\App\Integration\Connector\BitrixRestClient;
use InvalidArgumentException;
use RuntimeException;

final readonly class BitrixIntegrationCheckController
{
    public function __construct(
        private BitrixPortalInstallRepository $portalInstallRepository,
        private BitrixRoutingResolver $routingResolver,
        private BitrixTokenManager $tokenManager,
        private BitrixOpenLinesConnectorLifecycle $connectorLifecycle,
        private BitrixRestClient $restClient,
        private string $bitrixConnectorMode = '',
        private string $telegramGatewayBaseUrl = '',
        private string $telegramGatewayToken = '',
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $portalDomain = $this->requiredDomain($payload, 'portal_domain');
        $channelProvider = $this->optionalString($payload, 'channel_provider') ?? 'telegram';
        $managerAccountExternalId = $this->optionalString($payload, 'manager_account_external_id');
        $requestedLineId = $this->optionalString($payload, 'line_id');

        $install = $this->portalInstallRepository->findByPortalDomain($portalDomain);
        if ($install === null) {
            throw new InvalidArgumentException(sprintf(
                'Портал "%s" не найден. Сначала выполни "Подключить Портал".',
                $portalDomain,
            ));
        }

        $routeFromInstall = $this->routeFromInstall($install, $requestedLineId);
        $bindingProbe = $this->probeBinding($portalDomain, $channelProvider, $managerAccountExternalId, $requestedLineId);
        $runtimeProbe = $this->probeRuntime();
        $telegramGatewayProbe = $this->probeTelegramGateway($managerAccountExternalId);

        $tokenError = null;
        $route = $routeFromInstall;

        try {
            $route = $this->tokenManager->ensureValidRoute(
                $routeFromInstall,
                $managerAccountExternalId ?? $portalDomain,
            );
        } catch (RuntimeException $exception) {
            $tokenError = $exception->getMessage();
        }

        $tokenProbe = [
            'status' => $tokenError === null ? 'ok' : 'failed',
            'ok' => $tokenError === null,
            'access_token_present' => $route->accessToken !== null && $route->accessToken !== '',
            'refresh_token_present' => $route->refreshToken !== null && $route->refreshToken !== '',
            'expires_at' => $route->expiresAt->format(DATE_ATOM),
            'oauth_refresh_configured' => $route->oauthClientId !== null
                && $route->oauthClientId !== ''
                && $route->oauthClientSecret !== null
                && $route->oauthClientSecret !== '',
        ];
        if ($tokenError !== null) {
            $tokenProbe['message'] = $tokenError;
        }

        if ($tokenError !== null) {
            $portalProbe = [
                'status' => 'failed',
                'ok' => false,
                'method' => 'app.info',
                'message' => 'Проверка Bitrix API пропущена из-за невалидного/просроченного токена.',
                'details' => $tokenError,
            ];
            $connectorProbe = [
                'status' => 'failed',
                'ok' => false,
                'message' => 'Проверка коннектора пропущена: токен невалиден или истек.',
                'details' => $tokenError,
            ];
            $lineProbe = [
                'status' => 'failed',
                'ok' => false,
                'line_id' => is_string($bindingProbe['line_id'] ?? null)
                    ? (string) $bindingProbe['line_id']
                    : ($requestedLineId ?? ''),
                'method' => 'imopenlines.config.get',
                'message' => 'Проверка линии пропущена: токен невалиден или истек.',
                'details' => $tokenError,
            ];

            $overallOk = false;

            return [
                'status' => 'ok',
                'ok' => $overallOk,
                'portal_domain' => $portalDomain,
                'token' => $tokenProbe,
                'portal_api' => $portalProbe,
                'binding' => $bindingProbe,
                'connector' => $connectorProbe,
                'line' => $lineProbe,
                'runtime' => $runtimeProbe,
                'telegram_gateway' => $telegramGatewayProbe,
            ];
        }

        $portalProbe = $this->probePortalApi($route);
        $connectorProbe = $this->probeConnectorStatus(
            $route,
            is_string($bindingProbe['connector_id'] ?? null) ? (string) $bindingProbe['connector_id'] : $route->connectorId,
            is_string($bindingProbe['line_id'] ?? null) ? (string) $bindingProbe['line_id'] : ($requestedLineId ?? ''),
        );

        $lineId = $bindingProbe['line_id'] ?? $requestedLineId ?? '';
        $lineProbe = $this->probeLine($route, (string) $lineId);

        $overallOk = $portalProbe['ok'] === true
            && (($bindingProbe['ok'] ?? true) === true)
            && (($connectorProbe['ok'] ?? true) === true)
            && (($telegramGatewayProbe['ok'] ?? true) === true)
            && (($runtimeProbe['ok'] ?? true) === true)
            && ($lineProbe['status'] === 'ok' || $lineProbe['status'] === 'skipped' || $lineProbe['status'] === 'unknown');

        return [
            'status' => 'ok',
            'ok' => $overallOk,
            'portal_domain' => $portalDomain,
            'token' => $tokenProbe,
            'portal_api' => $portalProbe,
            'binding' => $bindingProbe,
            'connector' => $connectorProbe,
            'line' => $lineProbe,
            'runtime' => $runtimeProbe,
            'telegram_gateway' => $telegramGatewayProbe,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeRuntime(): array
    {
        $mode = strtolower(trim($this->bitrixConnectorMode));
        if ($mode === '' || $mode === 'rest') {
            return [
                'status' => 'ok',
                'ok' => true,
                'bitrix_connector_mode' => $this->bitrixConnectorMode,
                'message' => 'Рабочий режим коннектора Bitrix включен.',
            ];
        }

        return [
            'status' => 'failed',
            'ok' => false,
            'bitrix_connector_mode' => $this->bitrixConnectorMode,
            'message' => 'Включен BITRIX_CONNECTOR_MODE=stub. Отправка в реальный Bitrix отключена.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeConnectorStatus(BitrixRoutingContext $route, string $connectorId, string $lineId): array
    {
        if ($connectorId === '') {
            return [
                'status' => 'skipped',
                'ok' => true,
                'message' => 'connector_id не задан, проверка коннектора пропущена.',
            ];
        }
        if ($lineId === '') {
            return [
                'status' => 'skipped',
                'ok' => true,
                'message' => 'line_id не задан, проверка статуса коннектора пропущена.',
            ];
        }

        try {
            $response = $this->restClient->call(
                $route->restBaseUrl,
                'imconnector.status',
                $this->withAuth([
                    'CONNECTOR' => $connectorId,
                    'LINE' => $lineId,
                ], $route->accessToken),
            );
        } catch (RuntimeException $exception) {
            return [
                'status' => 'failed',
                'ok' => false,
                'message' => 'Не удалось получить статус коннектора.',
                'details' => $exception->getMessage(),
                'connector_id' => $connectorId,
                'line_id' => $lineId,
            ];
        }

        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            return [
                'status' => 'failed',
                'ok' => false,
                'message' => 'Некорректный ответ imconnector.status.',
                'connector_id' => $connectorId,
                'line_id' => $lineId,
                'response_preview' => $this->shortPreview($response),
            ];
        }

        $configured = $this->toBool($result['CONFIGURED'] ?? null);
        $active = $this->toBool($result['ACTIVE'] ?? ($result['STATUS'] ?? null));

        try {
            $this->connectorLifecycle->ensure(
                $route->restBaseUrl,
                $connectorId,
                $lineId,
                $route->accessToken,
            );

            $after = $this->restClient->call(
                $route->restBaseUrl,
                'imconnector.status',
                $this->withAuth([
                    'CONNECTOR' => $connectorId,
                    'LINE' => $lineId,
                ], $route->accessToken),
            );
            $afterResult = $after['result'] ?? null;
            $afterConfigured = is_array($afterResult) && $this->toBool($afterResult['CONFIGURED'] ?? null);
            $afterActive = is_array($afterResult) && $this->toBool($afterResult['ACTIVE'] ?? ($afterResult['STATUS'] ?? null));
            $afterOk = $afterConfigured && $afterActive;
            $statusChanged = (!$configured || !$active) && $afterOk;

            return [
                'status' => $afterOk ? 'ok' : 'failed',
                'ok' => $afterOk,
                'connector_id' => $connectorId,
                'line_id' => $lineId,
                'configured' => $afterConfigured,
                'active' => $afterActive,
                'message' => $afterOk
                    ? ($statusChanged
                        ? 'Коннектор был автоматически настроен (register/activate/data.set).'
                        : 'Коннектор активен; параметры webhook синхронизированы (data.set).')
                    : 'Автонастройка выполнена, но статус коннектора остался неготов.',
                'response_preview' => $this->shortPreview($after),
            ];
        } catch (RuntimeException $exception) {
            return [
                'status' => 'failed',
                'ok' => false,
                'connector_id' => $connectorId,
                'line_id' => $lineId,
                'configured' => $configured,
                'active' => $active,
                'message' => 'Коннектор не готов. Автонастройка не удалась.',
                'details' => $exception->getMessage(),
                'response_preview' => $this->shortPreview($response),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function probePortalApi(BitrixRoutingContext $route): array
    {
        $errors = [];

        foreach (['app.info', 'server.time'] as $method) {
            try {
                $response = $this->restClient->call(
                    $route->restBaseUrl,
                    $method,
                    $this->withAuth([], $route->accessToken),
                );

                if ($method === 'app.info') {
                    $appInfo = $response['result'] ?? null;
                    if (is_array($appInfo) && array_key_exists('INSTALLED', $appInfo) && $this->toBool($appInfo['INSTALLED']) !== true) {
                        return [
                            'status' => 'unknown',
                            'ok' => true,
                            'method' => $method,
                            'message' => 'app.info вернул INSTALLED=false. В некоторых порталах это не блокирует работу Open Lines, проверь доставку сообщением.',
                            'response_preview' => $this->shortPreview($response),
                        ];
                    }
                }

                return [
                    'status' => 'ok',
                    'ok' => true,
                    'method' => $method,
                    'message' => 'Bitrix API доступен.',
                    'response_preview' => $this->shortPreview($response),
                ];
            } catch (RuntimeException $exception) {
                $errors[] = sprintf('%s: %s', $method, $exception->getMessage());
            }
        }

        return [
            'status' => 'failed',
            'ok' => false,
            'message' => 'Не удалось подтвердить доступ к Bitrix API.',
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeBinding(
        string $portalDomain,
        string $channelProvider,
        ?string $managerAccountExternalId,
        ?string $requestedLineId,
    ): array {
        if ($managerAccountExternalId === null || $managerAccountExternalId === '') {
            return [
                'status' => 'skipped',
                'ok' => true,
                'message' => 'Проверка привязки менеджера пропущена (manager_account_external_id не передан).',
            ];
        }

        $route = $this->routingResolver->resolveForManagerAccount($channelProvider, $managerAccountExternalId);
        if ($route === null) {
            return [
                'status' => 'failed',
                'ok' => false,
                'message' => sprintf(
                    'Не найдена активная привязка для менеджера "%s".',
                    $managerAccountExternalId,
                ),
            ];
        }

        if (strtolower($route->portalDomain) !== strtolower($portalDomain)) {
            return [
                'status' => 'failed',
                'ok' => false,
                'message' => sprintf(
                    'Привязка менеджера указывает на другой портал: "%s".',
                    $route->portalDomain,
                ),
                'resolved_portal_domain' => $route->portalDomain,
                'line_id' => $route->lineId,
                'connector_id' => $route->connectorId,
            ];
        }

        if ($requestedLineId !== null && $requestedLineId !== '' && $requestedLineId !== $route->lineId) {
            return [
                'status' => 'failed',
                'ok' => false,
                'message' => sprintf(
                    'В привязке у менеджера line_id="%s", а проверяется "%s".',
                    $route->lineId,
                    $requestedLineId,
                ),
                'resolved_portal_domain' => $route->portalDomain,
                'line_id' => $route->lineId,
                'connector_id' => $route->connectorId,
            ];
        }

        return [
            'status' => 'ok',
            'ok' => true,
            'message' => 'Привязка менеджера активна.',
            'resolved_portal_domain' => $route->portalDomain,
            'line_id' => $route->lineId,
            'connector_id' => $route->connectorId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeLine(BitrixRoutingContext $route, string $lineId): array
    {
        if ($lineId === '') {
            return [
                'status' => 'skipped',
                'ok' => true,
                'message' => 'line_id не передан, проверка линии пропущена.',
            ];
        }

        try {
            $response = $this->restClient->call(
                $route->restBaseUrl,
                'imopenlines.config.get',
                $this->withAuth(['CONFIG_ID' => $lineId], $route->accessToken),
            );

            return [
                'status' => 'ok',
                'ok' => true,
                'line_id' => $lineId,
                'method' => 'imopenlines.config.get',
                'message' => 'Линия подтверждена в Bitrix.',
                'response_preview' => $this->shortPreview($response),
            ];
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $lower = strtolower($message);

            $permissionLimited = str_contains($lower, 'insufficient_scope')
                || str_contains($lower, 'access denied')
                || str_contains($lower, 'method not found')
                || str_contains($lower, 'unknown method')
                || str_contains($lower, 'imopenlines');

            if ($permissionLimited) {
                return [
                    'status' => 'unknown',
                    'ok' => true,
                    'line_id' => $lineId,
                    'method' => 'imopenlines.config.get',
                    'message' => 'Не удалось проверить линию через API. Обычно это права приложения (добавь imopenlines).',
                    'details' => $message,
                ];
            }

            return [
                'status' => 'failed',
                'ok' => false,
                'line_id' => $lineId,
                'method' => 'imopenlines.config.get',
                'message' => 'Проверка линии завершилась ошибкой.',
                'details' => $message,
            ];
        }
    }

    private function routeFromInstall(BitrixPortalInstall $install, ?string $lineId): BitrixRoutingContext
    {
        return new BitrixRoutingContext(
            portalDomain: $install->portalDomain,
            restBaseUrl: $install->restBaseUrl,
            connectorId: 'chat_sync',
            lineId: $lineId ?? '',
            accessToken: $install->accessToken,
            refreshToken: $install->refreshToken,
            oauthClientId: $install->oauthClientId,
            oauthClientSecret: $install->oauthClientSecret,
            oauthServerEndpoint: $install->oauthServerEndpoint,
            expiresAt: $install->expiresAt,
        );
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

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredDomain(array $payload, string $field): string
    {
        $value = strtolower($this->requiredString($payload, $field));
        if (preg_match('/^[a-z0-9.-]+$/', $value) !== 1 || !str_contains($value, '.')) {
            throw new InvalidArgumentException(sprintf('Поле "%s" должно быть доменом портала Bitrix.', $field));
        }

        return $value;
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

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function shortPreview(array $response): array
    {
        $preview = $response;
        if (isset($preview['result']) && is_array($preview['result']) && count($preview['result']) > 15) {
            $preview['result'] = ['_truncated' => true];
        }

        return $preview;
    }

    /**
     * @return array<string, mixed>
     */
    private function probeTelegramGateway(?string $managerAccountExternalId): array
    {
        $baseUrl = rtrim($this->telegramGatewayBaseUrl, '/');
        if ($baseUrl === '') {
            return [
                'status' => 'skipped',
                'ok' => true,
                'message' => 'TELEGRAM_GATEWAY_BASE_URL не задан.',
            ];
        }

        $endpoint = $baseUrl . '/v1/accounts';
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
                'status' => 'failed',
                'ok' => false,
                'message' => 'Не удалось получить данные из telegram-gateway.',
                'endpoint' => $endpoint,
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'status' => 'failed',
                'ok' => false,
                'message' => 'telegram-gateway вернул некорректный JSON.',
                'endpoint' => $endpoint,
            ];
        }

        $accounts = $decoded['accounts'] ?? null;
        if (!is_array($accounts)) {
            return [
                'status' => 'failed',
                'ok' => false,
                'message' => 'В ответе telegram-gateway нет поля accounts.',
                'endpoint' => $endpoint,
                'response_preview' => $this->shortPreview($decoded),
            ];
        }

        $result = [
            'status' => 'ok',
            'ok' => true,
            'endpoint' => $endpoint,
            'accounts_total' => count($accounts),
        ];

        if ($managerAccountExternalId === null || $managerAccountExternalId === '') {
            return $result;
        }

        foreach ($accounts as $item) {
            if (!is_array($item)) {
                continue;
            }
            $manager = $item['manager_account_external_id'] ?? null;
            if (!is_string($manager) || $manager !== $managerAccountExternalId) {
                continue;
            }

            $authorizationState = is_string($item['authorization_state'] ?? null)
                ? $item['authorization_state']
                : 'unknown';
            $dispatchStatus = is_string($item['last_dispatch_status'] ?? null)
                ? $item['last_dispatch_status']
                : 'unknown';
            $lastError = is_string($item['last_error'] ?? null) && trim($item['last_error']) !== ''
                ? trim($item['last_error'])
                : null;

            return [
                'status' => 'ok',
                'ok' => $authorizationState === 'authorizationStateReady' && $dispatchStatus !== 'failed',
                'endpoint' => $endpoint,
                'accounts_total' => count($accounts),
                'account_id' => is_string($item['account_id'] ?? null) ? $item['account_id'] : '',
                'manager_account_external_id' => $managerAccountExternalId,
                'authorization_state' => $authorizationState,
                'last_dispatch_status' => $dispatchStatus,
                'last_error' => $lastError,
                'message' => $authorizationState === 'authorizationStateReady'
                    ? 'Telegram аккаунт найден и авторизован.'
                    : 'Telegram аккаунт найден, но не авторизован.',
            ];
        }

        return [
            'status' => 'failed',
            'ok' => false,
            'endpoint' => $endpoint,
            'accounts_total' => count($accounts),
            'manager_account_external_id' => $managerAccountExternalId,
            'message' => 'В telegram-gateway нет аккаунта с этим manager_account_external_id.',
            'known_managers' => $this->knownManagers($accounts),
            'known_accounts' => $this->knownAccounts($accounts),
        ];
    }

    /**
     * @param array<int, mixed> $accounts
     * @return list<string>
     */
    private function knownManagers(array $accounts): array
    {
        $result = [];
        foreach ($accounts as $item) {
            if (!is_array($item)) {
                continue;
            }
            $manager = $item['manager_account_external_id'] ?? null;
            if (is_string($manager) && $manager !== '') {
                $result[] = $manager;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<int, mixed> $accounts
     * @return list<array<string, string>>
     */
    private function knownAccounts(array $accounts): array
    {
        $result = [];
        foreach ($accounts as $item) {
            if (!is_array($item)) {
                continue;
            }
            $accountId = $item['account_id'] ?? null;
            $manager = $item['manager_account_external_id'] ?? null;
            $authorizationState = $item['authorization_state'] ?? null;
            if (!is_string($accountId) || $accountId === '') {
                continue;
            }
            if (!is_string($manager) || $manager === '') {
                continue;
            }
            $result[] = [
                'account_id' => $accountId,
                'manager_account_external_id' => $manager,
                'authorization_state' => is_string($authorizationState) ? $authorizationState : 'unknown',
            ];
        }

        return $result;
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
            return in_array(strtolower($value), ['1', 'true', 't', 'yes', 'y', 'on'], true);
        }

        return false;
    }
}
