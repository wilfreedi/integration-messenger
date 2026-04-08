<?php

declare(strict_types=1);

namespace ChatSync\Shared\Infrastructure\Config;

use RuntimeException;

final readonly class EnvFileStore
{
    public function __construct(private string $path)
    {
    }

    /**
     * @return array<string, string>
     */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $result = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $name = trim($key);
            if ($name === '') {
                continue;
            }

            $result[$name] = trim(trim($value), "\"'");
        }

        return $result;
    }

    /**
     * @param array<string, string> $values
     */
    public function upsert(array $values): void
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $name = trim($key);
            if ($name === '') {
                continue;
            }
            $normalized[$name] = $this->normalizeValue($value);
        }

        if ($normalized === []) {
            return;
        }

        $lines = [];
        if (is_file($this->path)) {
            $loaded = file($this->path, FILE_IGNORE_NEW_LINES);
            if ($loaded !== false) {
                $lines = $loaded;
            }
        }

        /** @var array<string, int> $lineIndexes */
        $lineIndexes = [];
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }
            [$key] = explode('=', $trimmed, 2);
            $name = trim($key);
            if ($name !== '' && !array_key_exists($name, $lineIndexes)) {
                $lineIndexes[$name] = $index;
            }
        }

        foreach ($normalized as $name => $value) {
            $line = $name . '=' . $value;
            if (array_key_exists($name, $lineIndexes)) {
                $lines[$lineIndexes[$name]] = $line;
            } else {
                $lines[] = $line;
            }
        }

        $content = implode("\n", $lines);
        if ($content !== '') {
            $content .= "\n";
        }

        $result = @file_put_contents($this->path, $content, LOCK_EX);
        if ($result === false) {
            throw new RuntimeException(sprintf('Cannot write environment file "%s".', $this->path));
        }
    }

    private function normalizeValue(string $value): string
    {
        return str_replace(["\r", "\n"], '', trim($value));
    }
}
