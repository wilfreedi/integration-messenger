<?php

declare(strict_types=1);

namespace ChatSync\Shared\Infrastructure\Config;

final class EnvironmentLoader
{
    public function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $trimmed, 2);
            $name = trim($name);

            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            $normalizedValue = trim($value);
            $normalizedValue = trim($normalizedValue, "\"'");

            putenv(sprintf('%s=%s', $name, $normalizedValue));
            $_ENV[$name] = $normalizedValue;
            $_SERVER[$name] = $normalizedValue;
        }
    }
}

