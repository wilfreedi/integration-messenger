<?php

declare(strict_types=1);

namespace ChatSync\Shared\Domain;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}

