<?php

declare(strict_types=1);

use ChatSync\App\Bootstrap\ApplicationContainer;
use ChatSync\App\Http\Json;
use ChatSync\App\Security\PanelAccessGuard;
use ChatSync\Core\Application\Exception\ContactIdentityNotFound;
use ChatSync\Core\Application\Exception\CrmThreadNotFound;
use ChatSync\Core\Application\Exception\ManagerAccountNotFound;
use ChatSync\Shared\Infrastructure\Config\AppConfig;
use ChatSync\Shared\Infrastructure\Config\EnvironmentLoader;

require __DIR__ . '/../vendor/autoload.php';

(new EnvironmentLoader())->load(__DIR__ . '/../.env');

$json = new Json();
$container = new ApplicationContainer(AppConfig::fromEnvironment());
$panelAccess = new PanelAccessGuard($container->config());
$panelAccess->startSession();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$clientIp = clientIpAddress();
$userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

try {
    if ($panelAccess->isSensitivePath($path)) {
        $panelAccess->banIp($clientIp, 'sensitive_path_probe');
        $json->respond([
            'error' => 'access_denied',
            'message' => 'Access denied.',
        ], 403);
    }

    $ipStatus = $panelAccess->ipStatus($clientIp);
    if (($ipStatus['banned'] ?? false) === true) {
        $json->respond([
            'error' => 'access_denied',
            'message' => 'Access denied for this IP.',
        ], 403);
    }

    maintainBitrixPanelBootstrapSession($path, $container->config()->bitrixWebhookToken);

    if ($method === 'GET' && $path === '/health') {
        $json->respond($container->healthController()->handle());
    }

    if ($method === 'GET' && ($path === '/panel/login' || $path === '/panel/login/' || $path === '/panel/login.html')) {
        if ($panelAccess->isAuthenticated($clientIp, $userAgent)) {
            header('Location: /panel/bitrix', true, 302);
            exit;
        }

        servePanelHtmlPage('login.html');
    }

    if ($method === 'GET' && $path === '/api/panel/auth/status') {
        $json->respond($panelAccess->authStatus($clientIp, $userAgent));
    }

    if ($method === 'POST' && $path === '/api/panel/auth/login') {
        $payload = $json->decodeRequestBody();
        $password = $payload['password'] ?? null;
        if (!is_string($password)) {
            $json->respond([
                'status' => 'failed',
                'ok' => false,
                'message' => 'Field "password" must be a string.',
            ], 422);
        }

        $result = $panelAccess->attemptLogin($password, $clientIp, $userAgent);
        $statusCode = is_int($result['status_code'] ?? null)
            ? (int) $result['status_code']
            : (($result['ok'] ?? false) === true ? 200 : 401);
        unset($result['status_code']);

        $returnTo = safeReturnTo(is_string($payload['return_to'] ?? null) ? $payload['return_to'] : '');
        $result['redirect_to'] = $returnTo !== '' ? $returnTo : '/panel/bitrix';

        $json->respond($result, $statusCode);
    }

    if ($method === 'POST' && $path === '/api/panel/auth/logout') {
        $panelAccess->logout();
        $json->respond([
            'status' => 'ok',
            'ok' => true,
        ]);
    }

    if (requiresPanelAuthentication($method, $path)) {
        $providedToken = providedIntegrationToken();
        $allowBitrixAppBootstrap = hasBitrixPanelBootstrapSession()
            || hasValidBitrixBootstrapToken($container->config()->bitrixWebhookToken, $providedToken)
            || isBitrixAppContextRequest();
        $allowByManagementToken = hasValidManagementAccess(
            $container->config()->bitrixManagementToken,
            $providedToken,
        );

        if (
            !$panelAccess->isAuthenticated($clientIp, $userAgent)
            && !$allowBitrixAppBootstrap
            && !$allowByManagementToken
        ) {
            if (isPanelHtmlPath($path)) {
                redirectToPanelLogin(currentRequestUri());
            }

            $json->respond([
                'error' => 'auth_required',
                'message' => 'Panel authentication required.',
            ], 401);
        }
    }

    if ($method === 'GET' && ($path === '/panel/bitrix' || $path === '/panel/bitrix/' || $path === '/panel/bitrix.html')) {
        servePanelHtmlPage('bitrix.html');
    }

    if ($method === 'GET' && $path === '/panel') {
        header('Location: /panel/bitrix', true, 302);
        exit;
    }

    if (($method === 'GET' || $method === 'POST') && ($path === '/bitrix/app' || $path === '/bitrix/app/')) {
        if ($method === 'POST') {
            $payload = decodeInboundPayload();
            $token = resolveBitrixWebhookToken($payload);

            if (isLikelyBitrixEventPayload($payload)) {
                $json->respond(
                    $container->bitrixOpenLinesWebhookController()->handle(
                        $payload,
                        is_string($token) ? $token : '',
                    ),
                    202,
                );
            }

            $installPayload = normalizeBitrixInstallPayload($payload);
            if ($installPayload !== null) {
                try {
                    $container->bitrixAppInstallController()->handle($installPayload);
                } catch (Throwable) {
                    // Do not break app-open flow if install payload has partial/invalid fields.
                }
            }
        }

        redirectBitrixAppToPanel($_GET, $_POST);
        exit;
    }

    if ($method === 'GET' && $path === '/api/debug/state') {
        $json->respond($container->debugStateController()->handle());
    }

    if ($method === 'POST' && $path === '/api/webhooks/channel-message') {
        $json->respond($container->channelMessageWebhookController()->handle($json->decodeRequestBody()), 202);
    }

    if ($method === 'POST' && $path === '/api/webhooks/crm-message') {
        $json->respond($container->crmMessageWebhookController()->handle($json->decodeRequestBody()), 202);
    }

    if ($method === 'POST' && $path === '/api/webhooks/bitrix/open-lines') {
        $payload = decodeInboundPayload();
        $token = resolveBitrixWebhookToken($payload);
        $json->respond(
            $container->bitrixOpenLinesWebhookController()->handle(
                $payload,
                is_string($token) ? $token : '',
            ),
            202,
        );
    }

    if ($method === 'GET' && $path === '/api/bitrix/setup/profile') {
        $json->respond($container->bitrixSetupProfileController()->handle());
    }

    if ($method === 'POST' && $path === '/api/bitrix/setup/tokens/generate-missing') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $json->respond($container->bitrixSetupGenerateTokensController()->handle());
    }

    if ($method === 'POST' && $path === '/api/bitrix/app/install') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $json->respond($container->bitrixAppInstallController()->handle($json->decodeRequestBody()));
    }

    if ($method === 'POST' && $path === '/api/bitrix/connect-profile') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $json->respond($container->bitrixConnectProfileController()->handle($json->decodeRequestBody()));
    }

    if ($method === 'POST' && $path === '/api/bitrix/check') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $json->respond($container->bitrixIntegrationCheckController()->handle($json->decodeRequestBody()));
    }

    if ($method === 'GET' && $path === '/api/bitrix/logs') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $limit = $_GET['limit'] ?? 200;
        $resolvedLimit = is_numeric($limit) ? (int) $limit : 200;
        $json->respond($container->bitrixLogsController()->handle($resolvedLimit));
    }

    if ($method === 'POST' && $path === '/api/bitrix/logs/clear') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $json->respond($container->bitrixLogsController()->clear());
    }

    if ($method === 'GET' && $path === '/api/bitrix/telegram/accounts') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $json->respond($container->bitrixTelegramGatewayAccountsController()->list());
    }

    if ($method === 'POST' && $path === '/api/bitrix/telegram/accounts/manager') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $json->respond(
            $container->bitrixTelegramGatewayAccountsController()->rebind(
                $json->decodeRequestBody(),
            ),
        );
    }

    if ($method === 'GET' && $path === '/api/bitrix/portals') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $json->respond($container->bitrixPortalsController()->handle());
    }

    if ($method === 'POST' && $path === '/api/bitrix/bindings') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $json->respond($container->managerBitrixBindingController()->handle($json->decodeRequestBody()));
    }

    if ($method === 'GET' && $path === '/api/bitrix/bindings') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $json->respond($container->managerBitrixBindingsController()->handle());
    }

    if ($method === 'GET' && $path === '/api/manager-accounts') {
        assertSharedToken(
            $container->config()->bitrixManagementToken,
            providedIntegrationToken(),
            'Invalid integration management token.',
        );
        $channelProvider = $_GET['channel_provider'] ?? null;
        $json->respond(
            $container->managerAccountsController()->handle(
                is_string($channelProvider) ? $channelProvider : null,
            ),
        );
    }

    $json->respond([
        'error' => 'not_found',
        'message' => sprintf('Route "%s %s" is not defined.', $method, $path),
    ], 404);
} catch (InvalidArgumentException $exception) {
    $json->respond([
        'error' => 'validation_error',
        'message' => $exception->getMessage(),
    ], 422);
} catch (ManagerAccountNotFound | CrmThreadNotFound | ContactIdentityNotFound $exception) {
    $json->respond([
        'error' => 'not_found',
        'message' => $exception->getMessage(),
    ], 404);
} catch (Throwable $exception) {
    $response = [
        'error' => 'internal_error',
        'message' => 'Unexpected server error.',
    ];

    if ($container->config()->appDebug) {
        $response['details'] = $exception->getMessage();
    }

    $json->respond($response, 500);
}

function providedIntegrationToken(): string
{
    $header = $_SERVER['HTTP_X_INTEGRATION_TOKEN'] ?? '';
    if (is_string($header) && $header !== '') {
        return $header;
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (is_string($authorization) && preg_match('/^\s*Bearer\s+(.+)\s*$/i', $authorization, $matches) === 1) {
        return trim($matches[1]);
    }

    $token = $_GET['token'] ?? '';

    return is_string($token) ? $token : '';
}

function assertSharedToken(string $expectedToken, string $providedToken, string $errorMessage): void
{
    if ($expectedToken === '') {
        return;
    }

    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        throw new InvalidArgumentException($errorMessage);
    }
}

/**
 * @param array<string, mixed> $queryParams
 * @param array<string, mixed> $formParams
 */
function redirectBitrixAppToPanel(array $queryParams, array $formParams): void
{
    /** @var array<string, string> $params */
    $params = [];

    foreach ($queryParams as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $scalar = scalarParam($value);
        if ($scalar === null || $scalar === '') {
            continue;
        }
        $params[$key] = $scalar;
    }

    foreach ([
        'AUTH_ID',
        'REFRESH_ID',
        'APP_SID',
        'DOMAIN',
        'MEMBER_ID',
        'CLIENT_ENDPOINT',
        'AUTH_EXPIRES',
        'domain',
        'access_token',
        'refresh_token',
        'application_token',
        'member_id',
        'client_endpoint',
        'client_id',
        'client_secret',
        'server_endpoint',
        'expires',
        'expires_in',
    ] as $key) {
        if (!array_key_exists($key, $formParams)) {
            continue;
        }
        $scalar = scalarParam($formParams[$key]);
        if ($scalar === null || $scalar === '') {
            continue;
        }
        if (!array_key_exists($key, $params)) {
            $params[$key] = $scalar;
        }
    }

    $auth = $formParams['auth'] ?? null;
    if (is_array($auth)) {
        foreach ([
            'domain',
            'access_token',
            'refresh_token',
            'application_token',
            'member_id',
            'client_endpoint',
            'client_id',
            'client_secret',
            'server_endpoint',
            'expires',
            'expires_in',
        ] as $key) {
            if (!array_key_exists($key, $auth)) {
                continue;
            }
            $scalar = scalarParam($auth[$key]);
            if ($scalar === null || $scalar === '') {
                continue;
            }
            if (!array_key_exists($key, $params)) {
                $params[$key] = $scalar;
            }
        }
    }

    $query = http_build_query($params);
    $location = '/panel/bitrix' . ($query !== '' ? ('?' . $query) : '');
    header('Location: ' . $location, true, 302);
}

function scalarParam(mixed $value): ?string
{
    if (is_string($value)) {
        return trim($value);
    }
    if (is_int($value) || is_float($value) || is_bool($value)) {
        return (string) $value;
    }

    return null;
}

function requiresPanelAuthentication(string $method, string $path): bool
{
    if ($path === '/bitrix/app' || $path === '/bitrix/app/') {
        return strtoupper($method) !== 'POST';
    }

    if (str_starts_with($path, '/panel/bitrix')) {
        return true;
    }

    if (str_starts_with($path, '/api/bitrix/')) {
        return true;
    }

    if ($path === '/api/manager-accounts' || $path === '/api/debug/state') {
        return true;
    }

    return false;
}

function isPanelHtmlPath(string $path): bool
{
    return str_starts_with($path, '/panel/')
        || $path === '/bitrix/app'
        || $path === '/bitrix/app/';
}

function servePanelHtmlPage(string $fileName): never
{
    $safeFileName = basename($fileName);
    $fullPath = __DIR__ . '/panel/' . $safeFileName;
    if (!is_file($fullPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    readfile($fullPath);
    exit;
}

function clientIpAddress(): string
{
    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($forwardedFor) && $forwardedFor !== '') {
        $parts = explode(',', $forwardedFor);
        $first = trim($parts[0] ?? '');
        if ($first !== '') {
            return $first;
        }
    }

    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';

    return is_string($remoteAddress) && $remoteAddress !== '' ? $remoteAddress : '0.0.0.0';
}

function currentRequestUri(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    return is_string($uri) && $uri !== '' ? $uri : '/';
}

function safeReturnTo(string $returnTo): string
{
    if ($returnTo === '') {
        return '';
    }

    if (!str_starts_with($returnTo, '/')) {
        return '';
    }

    if (str_starts_with($returnTo, '//')) {
        return '';
    }

    return $returnTo;
}

function redirectToPanelLogin(string $returnTo): never
{
    $safePath = safeReturnTo($returnTo);
    $location = '/panel/login';
    if ($safePath !== '') {
        $location .= '?return_to=' . urlencode($safePath);
    }

    header('Location: ' . $location, true, 302);
    exit;
}

function isBitrixAppContextRequest(): bool
{
    $domain = scalarParam($_GET['DOMAIN'] ?? null)
        ?? scalarParam($_GET['domain'] ?? null)
        ?? scalarParam($_POST['DOMAIN'] ?? null)
        ?? scalarParam($_POST['domain'] ?? null);

    if (!is_string($domain) || $domain === '') {
        return false;
    }

    $hasAuthMarker = scalarParam($_GET['AUTH_ID'] ?? null) !== null
        || scalarParam($_GET['access_token'] ?? null) !== null
        || scalarParam($_GET['APP_SID'] ?? null) !== null
        || scalarParam($_GET['application_token'] ?? null) !== null
        || scalarParam($_POST['AUTH_ID'] ?? null) !== null
        || scalarParam($_POST['access_token'] ?? null) !== null
        || scalarParam($_POST['APP_SID'] ?? null) !== null
        || scalarParam($_POST['application_token'] ?? null) !== null;

    if ($hasAuthMarker) {
        return true;
    }

    $auth = $_POST['auth'] ?? null;
    if (is_array($auth)) {
        return scalarParam($auth['access_token'] ?? null) !== null
            || scalarParam($auth['application_token'] ?? null) !== null;
    }

    return $hasAuthMarker;
}

function hasValidManagementAccess(string $expectedToken, string $providedToken): bool
{
    if ($expectedToken === '') {
        return false;
    }

    return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
}

function hasValidBitrixBootstrapToken(string $expectedToken, string $providedToken): bool
{
    if ($expectedToken === '') {
        return false;
    }

    return $providedToken !== '' && hash_equals($expectedToken, $providedToken);
}

function maintainBitrixPanelBootstrapSession(string $path, string $bitrixWebhookToken): void
{
    if (!isPanelHtmlPath($path)) {
        return;
    }

    if (!isBitrixBootstrapRequest($path, $bitrixWebhookToken)) {
        return;
    }

    $_SESSION['bitrix_panel_bootstrap_until'] = time() + 86400;
}

function hasBitrixPanelBootstrapSession(): bool
{
    $expiresAt = $_SESSION['bitrix_panel_bootstrap_until'] ?? null;
    if (!is_int($expiresAt)) {
        return false;
    }

    if ($expiresAt <= time()) {
        unset($_SESSION['bitrix_panel_bootstrap_until']);
        return false;
    }

    return true;
}

function isBitrixBootstrapRequest(string $path, string $bitrixWebhookToken): bool
{
    if (isBitrixAppContextRequest()) {
        return true;
    }

    if (isBitrixRefererContext()) {
        return true;
    }

    $token = scalarParam($_GET['token'] ?? null);
    if (
        ($path === '/bitrix/app' || $path === '/bitrix/app/')
        && $bitrixWebhookToken !== ''
        && $token !== null
        && hash_equals($bitrixWebhookToken, $token)
    ) {
        return true;
    }

    return false;
}

function isBitrixRefererContext(): bool
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (!is_string($referer) || trim($referer) === '') {
        return false;
    }

    $host = parse_url($referer, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return false;
    }

    $host = strtolower($host);
    if (str_ends_with($host, '.bitrix24.ru') || str_contains($host, 'bitrix24')) {
        return true;
    }

    $domain = scalarParam($_GET['DOMAIN'] ?? null) ?? scalarParam($_GET['domain'] ?? null);
    if (is_string($domain) && $domain !== '' && strtolower($domain) === $host) {
        return true;
    }

    return false;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|null
 */
function normalizeBitrixInstallPayload(array $payload): ?array
{
    $authPayload = [];

    $auth = $payload['auth'] ?? null;
    if (is_array($auth)) {
        foreach ([
            'domain',
            'member_id',
            'access_token',
            'refresh_token',
            'application_token',
            'client_endpoint',
            'scope',
            'expires',
            'expires_in',
            'client_id',
            'client_secret',
            'server_endpoint',
        ] as $key) {
            $value = scalarParam($auth[$key] ?? null);
            if ($value !== null && $value !== '') {
                $authPayload[$key] = $value;
            }
        }
    }

    $mapping = [
        'domain' => ['domain', 'DOMAIN'],
        'member_id' => ['member_id', 'MEMBER_ID'],
        'access_token' => ['access_token', 'AUTH_ID'],
        'refresh_token' => ['refresh_token', 'REFRESH_ID'],
        'application_token' => ['application_token', 'APP_SID'],
        'client_endpoint' => ['client_endpoint', 'CLIENT_ENDPOINT'],
        'scope' => ['scope', 'SCOPE'],
        'expires' => ['expires', 'AUTH_EXPIRES'],
        'expires_in' => ['expires_in', 'AUTH_EXPIRES'],
        'client_id' => ['client_id', 'CLIENT_ID'],
        'client_secret' => ['client_secret', 'CLIENT_SECRET'],
        'server_endpoint' => ['server_endpoint', 'SERVER_ENDPOINT'],
    ];

    foreach ($mapping as $target => $sources) {
        if (isset($authPayload[$target])) {
            continue;
        }

        foreach ($sources as $source) {
            $value = scalarParam($payload[$source] ?? null);
            if ($value !== null && $value !== '') {
                $authPayload[$target] = $value;
                break;
            }
        }
    }

    $required = ['domain', 'access_token', 'refresh_token', 'application_token'];
    foreach ($required as $field) {
        if (!isset($authPayload[$field]) || !is_string($authPayload[$field]) || $authPayload[$field] === '') {
            return null;
        }
    }

    return [
        'auth' => $authPayload,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function isLikelyBitrixEventPayload(array $payload): bool
{
    if (isset($payload['event']) || isset($payload['EVENT'])) {
        return true;
    }

    $data = $payload['data'] ?? $payload['DATA'] ?? null;
    if (is_array($data)) {
        return isset($data['DATA']) || isset($data['MESSAGES']) || isset($data['FIELDS']);
    }

    if (is_string($data) && trim($data) !== '') {
        return true;
    }

    return false;
}

/**
 * @return array<string, mixed>
 */
function decodeInboundPayload(): array
{
    if ($_POST !== []) {
        /** @var array<string, mixed> $payload */
        $payload = $_POST;
        return $payload;
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decodedJson = json_decode($raw, true);
    if (is_array($decodedJson)) {
        /** @var array<string, mixed> $payload */
        $payload = $decodedJson;
        return $payload;
    }

    $parsed = [];
    parse_str($raw, $parsed);
    if (is_array($parsed) && $parsed !== []) {
        /** @var array<string, mixed> $payload */
        $payload = $parsed;
        return $payload;
    }

    return [];
}

/**
 * @param array<string, mixed> $payload
 */
function resolveBitrixWebhookToken(array $payload): string
{
    $candidates = [
        scalarParam($_GET['token'] ?? null),
        scalarParam($_POST['token'] ?? null),
        scalarParam($payload['token'] ?? null),
        scalarParam($payload['TOKEN'] ?? null),
        scalarParam($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null),
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }
    }

    return '';
}
