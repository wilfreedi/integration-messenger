<?php

declare(strict_types=1);

use ChatSync\App\Bootstrap\ApplicationContainer;
use ChatSync\App\Http\Json;
use ChatSync\Core\Application\Exception\ContactIdentityNotFound;
use ChatSync\Core\Application\Exception\CrmThreadNotFound;
use ChatSync\Core\Application\Exception\ManagerAccountNotFound;
use ChatSync\Shared\Infrastructure\Config\AppConfig;
use ChatSync\Shared\Infrastructure\Config\EnvironmentLoader;

require __DIR__ . '/../vendor/autoload.php';

(new EnvironmentLoader())->load(__DIR__ . '/../.env');

$json = new Json();
$container = new ApplicationContainer(AppConfig::fromEnvironment());
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

try {
    if ($method === 'GET' && $path === '/health') {
        $json->respond($container->healthController()->handle());
    }

    if ($method === 'GET' && $path === '/panel/bitrix') {
        header('Location: /panel/bitrix.html', true, 302);
        exit;
    }

    if ($method === 'GET' && $path === '/bitrix/app') {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        $location = '/panel/bitrix.html' . (is_string($query) && $query !== '' ? ('?' . $query) : '');
        header('Location: ' . $location, true, 302);
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
        $token = $_GET['token'] ?? '';
        $payload = $json->decodeRequestBody();
        if ($payload === [] && $_POST !== []) {
            /** @var array<string, mixed> $payload */
            $payload = $_POST;
        }
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
