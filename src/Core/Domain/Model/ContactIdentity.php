<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Model;

use ChatSync\Core\Domain\Enum\ContactIdentityType;
use ChatSync\Core\Domain\ValueObject\ContactId;
use ChatSync\Core\Domain\ValueObject\ContactIdentityId;
use DateTimeImmutable;

final readonly class ContactIdentity
{
    public function __construct(
        private ContactIdentityId $id,
        private ContactId $contactId,
        private string $provider,
        private ContactIdentityType $type,
        private string $value,
        private bool $isPrimary,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): ContactIdentityId
    {
        return $this->id;
    }

    public function contactId(): ContactId
    {
        return $this->contactId;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function type(): ContactIdentityType
    {
        return $this->type;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

