<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Model;

use ChatSync\Core\Domain\ValueObject\ContactId;
use DateTimeImmutable;

final readonly class Contact
{
    public function __construct(
        private ContactId $id,
        private string $displayName,
        private ?string $primaryPhone,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): ContactId
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function primaryPhone(): ?string
    {
        return $this->primaryPhone;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

