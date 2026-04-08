<?php

declare(strict_types=1);

namespace ChatSync\Shared\Domain;

interface IdGenerator
{
    public function next(): string;
}

