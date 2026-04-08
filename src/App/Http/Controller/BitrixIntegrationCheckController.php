<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Integration\Bitrix\BitrixPortalInstall;
use ChatSync\App\Integration\Bitrix\BitrixPortalInstallRepository;
use ChatSync\App\Integration\Bitrix\BitrixRoutingContext;
use ChatSync\App\Integration\Bitrix\BitrixRoutingResolver;
use ChatSync\App\Integration\Bitrix\BitrixTokenManager;
use ChatSync\App\Integration\Connector\BitrixRestClient;
use InvalidArgumentException;
use RuntimeException;

final readonly class BitrixIntegrationCheckController
{
    public function __construct(
        private BitrixPortalInstallRepository $portalInstallRepository,
        private BitrixRoutingResolver $routingResolver,
        private BitrixTokenManager $tokenManager,
        private BitrixRestClient $restClient,
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
        $validatedRoute = $this->tokenManager->ensureValidRoute(
            $routeFromInstall,
            $managerAccountExternalId ?? $portalDomain,
        );

        $portalProbe = $this->probePortalApi($validatedRoute);
        $bindingProbe = $this->probeBinding($portalDomain, $channelProvider, $managerAccountExternalId, $requestedLineId);

        $lineId = $bindingProbe['line_id'] ?? $requestedLineId ?? '';
        $lineProbe = $this->probeLine($validatedRoute, (string) $lineId);

        $overallOk = $portalProbe['ok'] === true
            && (($bindingProbe['ok'] ?? true) === true)
            && ($lineProbe['status'] === 'ok' || $lineProbe['status'] === 'skipped' || $lineProbe['status'] === 'unknown');

        return [
            'status' => 'ok',
            'ok' => $overallOk,
            'portal_domain' => $portalDomain,
            'token' => [
                'status' => 'ok',
                'access_token_present' => $validatedRoute->accessToken !== null && $validatedRoute->accessToken !== '',
                'refresh_token_present' => $validatedRoute->refreshToken !== null && $validatedRoute->refreshToken !== '',
                'expires_at' => $validatedRoute->expiresAt->format(DATE_ATOM),
                'oauth_refresh_configured' => $validatedRoute->oauthClientId !== null
                    && $validatedRoute->oauthClientId !== ''
                    && $validatedRoute->oauthClientSecret !== null
                    && $validatedRoute->oauthClientSecret !== '',
            ],
            'portal_api' => $portalProbe,
            'binding' => $bindingProbe,
            'line' => $lineProbe,
        ];
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
}

