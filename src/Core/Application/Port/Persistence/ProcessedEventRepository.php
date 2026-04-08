<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Persistence;

interface ProcessedEventRepository
{
    public function has(string $source, string $eventId): bool;

    public function add(string $source, string $eventId): void;
}

