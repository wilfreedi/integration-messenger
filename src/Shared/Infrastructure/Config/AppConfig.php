<?php

declare(strict_types=1);

namespace ChatSync\Shared\Infrastructure\Config;

use RuntimeException;

final readonly class AppConfig
{
    public function __construct(
        public string $appEnv,
        public int $appPort,
        public bool $appDebug,
        public bool $bootstrapDemoData,
        public string $siteDomain,
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        public string $bitrixConnectorMode,
        public string $bitrixWebhookToken,
        public string $bitrixManagementToken,
        public string $bitrixDefaultChannelProvider,
        public string $telegramConnectorMode,
        public string $telegramGatewayBaseUrl,
        public string $telegramGatewayToken,
        public string $panelAuthPassword,
        public int $panelAuthSessionTtlSeconds,
        public int $panelAuthMaxAttempts,
        public int $panelAuthLockSeconds,
        public int $panelAuthBanSeconds,
        public int $panelAuthPasswordMaxLength,
        public string $panelAuthStateFile,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::env('APP_ENV', 'dev'),
            self::envInt('APP_PORT', 8080),
            self::envBool('APP_DEBUG', true),
            self::envBool('APP_BOOTSTRAP_DEMO_DATA', true),
            self::env('SITE_DOMAIN', ''),
            self::env('DB_HOST', '127.0.0.1'),
            self::envInt('DB_PORT', 5432),
            self::env('DB_NAME'),
            self::env('DB_USER'),
            self::env('DB_PASSWORD'),
            self::env('BITRIX_CONNECTOR_MODE', 'rest'),
            self::env('BITRIX_WEBHOOK_TOKEN', ''),
            self::env('BITRIX_MANAGEMENT_TOKEN', ''),
            self::env('BITRIX_DEFAULT_CHANNEL_PROVIDER', 'telegram'),
            self::env('TELEGRAM_CONNECTOR_MODE', 'stub'),
            rtrim(self::env('TELEGRAM_GATEWAY_BASE_URL', 'http://telegram-gateway:8090'), '/'),
            self::env('TELEGRAM_GATEWAY_TOKEN', ''),
            self::env('PANEL_AUTH_PASSWORD', ''),
            max(300, self::envInt('PANEL_AUTH_SESSION_TTL_SECONDS', 86400)),
            max(1, self::envInt('PANEL_AUTH_MAX_ATTEMPTS', 5)),
            max(60, self::envInt('PANEL_AUTH_LOCK_SECONDS', 900)),
            max(60, self::envInt('PANEL_AUTH_BAN_SECONDS', 315360000)),
            max(8, self::envInt('PANEL_AUTH_PASSWORD_MAX_LENGTH', 128)),
            self::env('PANEL_AUTH_STATE_FILE', '/tmp/chat-sync-panel-auth-state.json'),
        );
    }

    private static function env(string $name, ?string $default = null): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            if ($default !== null) {
                return $default;
            }

            throw new RuntimeException(sprintf('Environment variable "%s" is required.', $name));
        }

        return $value;
    }

    private static function envInt(string $name, int $default): int
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    private static function envBool(string $name, bool $default): bool
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
