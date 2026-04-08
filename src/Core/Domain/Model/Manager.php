<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Model;

use ChatSync\Core\Domain\ValueObject\ManagerId;
use DateTimeImmutable;

final readonly class Manager
{
    public function __construct(
        private ManagerId $id,
        private string $displayName,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): ManagerId
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

