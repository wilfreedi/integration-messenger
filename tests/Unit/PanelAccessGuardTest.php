<?php

declare(strict_types=1);

namespace ChatSync\Tests\Unit;

use ChatSync\App\Security\PanelAccessGuard;
use ChatSync\Shared\Infrastructure\Config\AppConfig;
use ChatSync\Tests\Support\Assertions;

final class PanelAccessGuardTest
{
    public static function run(): void
    {
        self::itLocksIpAfterFailedAttempts();
        self::itAuthenticatesAndStoresSession();
        self::itBansSensitivePathProbe();
    }

    private static function itLocksIpAfterFailedAttempts(): void
    {
        self::resetSession();
        $stateFile = self::tempStateFile('lock');
        $guard = new PanelAccessGuard(self::config($stateFile, 2));
        $guard->startSession();

        $first = $guard->attemptLogin('wrong', '10.0.0.2', 'ua');
        Assertions::assertFalse(($first['ok'] ?? false) === true);
        Assertions::assertSame(401, $first['status_code'] ?? 0);

        $second = $guard->attemptLogin('still-wrong', '10.0.0.2', 'ua');
        Assertions::assertFalse(($second['ok'] ?? false) === true);
        Assertions::assertSame(423, $second['status_code'] ?? 0);

        self::cleanupStateFile($stateFile);
        self::resetSession();
    }

    private static function itAuthenticatesAndStoresSession(): void
    {
        self::resetSession();
        $stateFile = self::tempStateFile('auth');
        $guard = new PanelAccessGuard(self::config($stateFile, 5));
        $guard->startSession();

        $result = $guard->attemptLogin('strong-password', '10.0.0.3', 'ua-test');
        Assertions::assertTrue(($result['ok'] ?? false) === true);
        Assertions::assertTrue($guard->isAuthenticated('10.0.0.3', 'ua-test'));

        $guard->logout();
        Assertions::assertFalse($guard->isAuthenticated('10.0.0.3', 'ua-test'));

        self::cleanupStateFile($stateFile);
        self::resetSession();
    }

    private static function itBansSensitivePathProbe(): void
    {
        self::resetSession();
        $stateFile = self::tempStateFile('ban');
        $guard = new PanelAccessGuard(self::config($stateFile, 5));
        $guard->startSession();

        Assertions::assertTrue($guard->isSensitivePath('/.env'));
        $guard->banIp('10.0.0.4', 'sensitive_path_probe');
        $status = $guard->ipStatus('10.0.0.4');
        Assertions::assertTrue(($status['banned'] ?? false) === true);

        self::cleanupStateFile($stateFile);
        self::resetSession();
    }

    private static function config(string $stateFile, int $maxAttempts): AppConfig
    {
        return new AppConfig(
            appEnv: 'test',
            appPort: 8080,
            appDebug: true,
            bootstrapDemoData: false,
            siteDomain: 'example.com',
            dbHost: 'localhost',
            dbPort: 5432,
            dbName: 'chat_sync',
            dbUser: 'chat_sync',
            dbPassword: 'chat_sync',
            bitrixConnectorMode: 'rest',
            bitrixWebhookToken: '',
            bitrixManagementToken: '',
            bitrixDefaultChannelProvider: 'telegram',
            telegramConnectorMode: 'stub',
            telegramGatewayBaseUrl: 'http://telegram-gateway:8090',
            telegramGatewayToken: '',
            panelAuthPassword: 'strong-password',
            panelAuthSessionTtlSeconds: 86400,
            panelAuthMaxAttempts: $maxAttempts,
            panelAuthLockSeconds: 120,
            panelAuthBanSeconds: 600,
            panelAuthPasswordMaxLength: 128,
            panelAuthStateFile: $stateFile,
        );
    }

    private static function tempStateFile(string $suffix): string
    {
        $uniq = uniqid('panel-auth-' . $suffix . '-', true);
        if ($uniq === false) {
            $uniq = 'panel-auth-' . $suffix . '-' . mt_rand();
        }

        return sys_get_temp_dir() . '/' . $uniq . '.json';
    }

    private static function cleanupStateFile(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function resetSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }
    }
}

