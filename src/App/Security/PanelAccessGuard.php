<?php

declare(strict_types=1);

namespace ChatSync\App\Security;

use ChatSync\Shared\Infrastructure\Config\AppConfig;
use RuntimeException;

final class PanelAccessGuard
{
    private const SESSION_NAME = 'chat_panel_sid';
    private const SESSION_KEY = 'panel_auth';

    public function __construct(private readonly AppConfig $config)
    {
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => $this->config->panelAuthSessionTtlSeconds,
            'path' => '/',
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public function isSensitivePath(string $path): bool
    {
        $normalized = strtolower(rawurldecode($path));

        return preg_match('#(^|/)\.env($|[./])#', $normalized) === 1;
    }

    /**
     * @return array{
     *   banned: bool,
     *   ban_until: int,
     *   locked: bool,
     *   lock_until: int,
     *   failed_attempts: int,
     *   remaining_attempts: int,
     *   ban_reason: string
     * }
     */
    public function ipStatus(string $ip): array
    {
        return $this->updateState(function (array &$state) use ($ip): array {
            $entry = $this->normalizedEntry($state, $ip);
            $now = time();
            $changed = false;

            if (($entry['lock_until'] ?? 0) < $now && (int) ($entry['lock_until'] ?? 0) !== 0) {
                $entry['lock_until'] = 0;
                $entry['failed_attempts'] = 0;
                $changed = true;
            }

            if (($entry['ban_until'] ?? 0) < $now && (int) ($entry['ban_until'] ?? 0) !== 0) {
                $entry['ban_until'] = 0;
                $entry['ban_reason'] = '';
                $changed = true;
            }

            if ($changed) {
                $state['ips'][$ip] = $entry;
            }

            $failedAttempts = (int) ($entry['failed_attempts'] ?? 0);
            $remainingAttempts = max(0, $this->config->panelAuthMaxAttempts - $failedAttempts);

            return [
                'banned' => (int) ($entry['ban_until'] ?? 0) > $now,
                'ban_until' => (int) ($entry['ban_until'] ?? 0),
                'locked' => (int) ($entry['lock_until'] ?? 0) > $now,
                'lock_until' => (int) ($entry['lock_until'] ?? 0),
                'failed_attempts' => $failedAttempts,
                'remaining_attempts' => $remainingAttempts,
                'ban_reason' => (string) ($entry['ban_reason'] ?? ''),
            ];
        });
    }

    public function banIp(string $ip, string $reason): void
    {
        $this->updateState(function (array &$state) use ($ip, $reason): array {
            $entry = $this->normalizedEntry($state, $ip);
            $entry['ban_until'] = time() + $this->config->panelAuthBanSeconds;
            $entry['ban_reason'] = $reason;
            $entry['failed_attempts'] = 0;
            $entry['lock_until'] = 0;
            $entry['last_failed_at'] = time();
            $state['ips'][$ip] = $entry;

            return [];
        });
    }

    public function isAuthenticated(string $ip, string $userAgent): bool
    {
        $this->startSession();

        if ($this->config->panelAuthPassword === '') {
            return false;
        }

        $auth = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($auth)) {
            return false;
        }

        $expiresAt = (int) ($auth['expires_at'] ?? 0);
        if ($expiresAt <= time()) {
            unset($_SESSION[self::SESSION_KEY]);
            return false;
        }

        $sessionIp = (string) ($auth['ip'] ?? '');
        if ($sessionIp === '' || !hash_equals($sessionIp, $ip)) {
            unset($_SESSION[self::SESSION_KEY]);
            return false;
        }

        $sessionUaHash = (string) ($auth['ua_hash'] ?? '');
        $currentUaHash = hash('sha256', $userAgent);
        if ($sessionUaHash === '' || !hash_equals($sessionUaHash, $currentUaHash)) {
            unset($_SESSION[self::SESSION_KEY]);
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function authStatus(string $ip, string $userAgent): array
    {
        $status = $this->ipStatus($ip);
        $authenticated = $this->isAuthenticated($ip, $userAgent);
        $auth = $_SESSION[self::SESSION_KEY] ?? [];
        $expiresAt = is_array($auth) ? (int) ($auth['expires_at'] ?? 0) : 0;

        return [
            'status' => 'ok',
            'authenticated' => $authenticated,
            'session_expires_at' => $expiresAt > 0 ? gmdate(DATE_ATOM, $expiresAt) : null,
            'password_configured' => $this->config->panelAuthPassword !== '',
            'ip' => $ip,
            'ip_block' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attemptLogin(string $password, string $ip, string $userAgent): array
    {
        $this->startSession();

        if ($this->config->panelAuthPassword === '') {
            return [
                'status' => 'failed',
                'ok' => false,
                'status_code' => 503,
                'message' => 'Panel password is not configured.',
            ];
        }

        $passwordLength = strlen($password);
        if ($passwordLength < 1 || $passwordLength > $this->config->panelAuthPasswordMaxLength) {
            $status = $this->recordFailedAttempt($ip, 'invalid_input_length');

            return [
                'status' => 'failed',
                'ok' => false,
                'status_code' => ($status['locked'] ?? false) ? 423 : 422,
                'message' => sprintf(
                    'Password must be between 1 and %d characters.',
                    $this->config->panelAuthPasswordMaxLength,
                ),
                'ip_block' => $status,
            ];
        }

        $ipStatus = $this->ipStatus($ip);
        if (($ipStatus['banned'] ?? false) === true) {
            return [
                'status' => 'failed',
                'ok' => false,
                'status_code' => 403,
                'message' => 'Access denied for this IP.',
                'ip_block' => $ipStatus,
            ];
        }

        if (($ipStatus['locked'] ?? false) === true) {
            return [
                'status' => 'failed',
                'ok' => false,
                'status_code' => 423,
                'message' => 'Too many failed attempts. Try again later.',
                'ip_block' => $ipStatus,
            ];
        }

        if (!hash_equals($this->config->panelAuthPassword, $password)) {
            $status = $this->recordFailedAttempt($ip, 'invalid_password');

            return [
                'status' => 'failed',
                'ok' => false,
                'status_code' => ($status['locked'] ?? false) ? 423 : 401,
                'message' => 'Invalid password.',
                'ip_block' => $status,
            ];
        }

        $this->clearFailures($ip);
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'issued_at' => time(),
            'expires_at' => time() + $this->config->panelAuthSessionTtlSeconds,
            'ip' => $ip,
            'ua_hash' => hash('sha256', $userAgent),
        ];

        return [
            'status' => 'ok',
            'ok' => true,
            'message' => 'Authenticated.',
            'session_expires_at' => gmdate(DATE_ATOM, time() + $this->config->panelAuthSessionTtlSeconds),
        ];
    }

    public function logout(): void
    {
        $this->startSession();
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function recordFailedAttempt(string $ip, string $reason): array
    {
        return $this->updateState(function (array &$state) use ($ip, $reason): array {
            $entry = $this->normalizedEntry($state, $ip);
            $now = time();

            if ((int) ($entry['lock_until'] ?? 0) > $now) {
                return $this->ipStatusFromEntry($entry, $now);
            }

            $entry['failed_attempts'] = (int) ($entry['failed_attempts'] ?? 0) + 1;
            $entry['last_failed_at'] = $now;
            $entry['last_failed_reason'] = $reason;

            if ((int) $entry['failed_attempts'] >= $this->config->panelAuthMaxAttempts) {
                $entry['lock_until'] = $now + $this->config->panelAuthLockSeconds;
                $entry['failed_attempts'] = 0;
            }

            $state['ips'][$ip] = $entry;

            return $this->ipStatusFromEntry($entry, $now);
        });
    }

    private function clearFailures(string $ip): void
    {
        $this->updateState(function (array &$state) use ($ip): array {
            $entry = $this->normalizedEntry($state, $ip);
            $entry['failed_attempts'] = 0;
            $entry['lock_until'] = 0;
            $entry['last_failed_reason'] = '';
            $state['ips'][$ip] = $entry;

            return [];
        });
    }

    /**
     * @param callable(array<string, mixed>&):array<string, mixed> $mutator
     * @return array<string, mixed>
     */
    private function updateState(callable $mutator): array
    {
        $stateFile = $this->config->panelAuthStateFile;
        $stateDir = dirname($stateFile);
        if ($stateDir !== '' && !is_dir($stateDir)) {
            mkdir($stateDir, 0775, true);
        }

        $handle = fopen($stateFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open panel auth state file: %s', $stateFile));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire lock for panel auth state file.');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = $this->decodeState(is_string($raw) ? $raw : '');
            $result = $mutator($state);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{"ips":{}}');
            fflush($handle);
            flock($handle, LOCK_UN);

            return $result;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeState(string $raw): array
    {
        if ($raw === '') {
            return ['ips' => []];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ips' => []];
        }

        $ips = $decoded['ips'] ?? null;
        if (!is_array($ips)) {
            $decoded['ips'] = [];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function normalizedEntry(array $state, string $ip): array
    {
        $ips = $state['ips'] ?? [];
        if (!is_array($ips)) {
            return [
                'failed_attempts' => 0,
                'lock_until' => 0,
                'ban_until' => 0,
                'ban_reason' => '',
                'last_failed_at' => 0,
                'last_failed_reason' => '',
            ];
        }

        $entry = $ips[$ip] ?? null;
        if (!is_array($entry)) {
            return [
                'failed_attempts' => 0,
                'lock_until' => 0,
                'ban_until' => 0,
                'ban_reason' => '',
                'last_failed_at' => 0,
                'last_failed_reason' => '',
            ];
        }

        return [
            'failed_attempts' => (int) ($entry['failed_attempts'] ?? 0),
            'lock_until' => (int) ($entry['lock_until'] ?? 0),
            'ban_until' => (int) ($entry['ban_until'] ?? 0),
            'ban_reason' => (string) ($entry['ban_reason'] ?? ''),
            'last_failed_at' => (int) ($entry['last_failed_at'] ?? 0),
            'last_failed_reason' => (string) ($entry['last_failed_reason'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function ipStatusFromEntry(array $entry, int $now): array
    {
        $failedAttempts = (int) ($entry['failed_attempts'] ?? 0);

        return [
            'banned' => (int) ($entry['ban_until'] ?? 0) > $now,
            'ban_until' => (int) ($entry['ban_until'] ?? 0),
            'locked' => (int) ($entry['lock_until'] ?? 0) > $now,
            'lock_until' => (int) ($entry['lock_until'] ?? 0),
            'failed_attempts' => $failedAttempts,
            'remaining_attempts' => max(0, $this->config->panelAuthMaxAttempts - $failedAttempts),
            'ban_reason' => (string) ($entry['ban_reason'] ?? ''),
        ];
    }

    private function isHttpsRequest(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_string($forwardedProto) && strtolower(trim($forwardedProto)) === 'https') {
            return true;
        }

        return false;
    }
}
