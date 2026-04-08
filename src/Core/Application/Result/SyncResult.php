<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Result;

final readonly class SyncResult
{
    private function __construct(
        public bool $processed,
        public string $reason,
        public ?string $messageId,
    ) {
    }

    public static function processed(string $messageId): self
    {
        return new self(true, 'processed', $messageId);
    }

    public static function duplicate(string $reason): self
    {
        return new self(false, $reason, null);
    }
}

