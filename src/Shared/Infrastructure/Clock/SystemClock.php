<?php

declare(strict_types=1);

namespace ChatSync\Shared\Infrastructure\Clock;

use ChatSync\Shared\Domain\Clock;
use DateTimeImmutable;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}

