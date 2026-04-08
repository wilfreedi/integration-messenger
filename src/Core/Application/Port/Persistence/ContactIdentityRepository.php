<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Persistence;

use ChatSync\Core\Domain\Enum\ContactIdentityType;
use ChatSync\Core\Domain\Model\ContactIdentity;
use ChatSync\Core\Domain\ValueObject\ContactId;

interface ContactIdentityRepository
{
    public function findByProviderTypeAndValue(string $provider, ContactIdentityType $type, string $value): ?ContactIdentity;

    public function findPrimaryForContactAndProvider(
        ContactId $contactId,
        string $provider,
        ContactIdentityType $type,
    ): ?ContactIdentity;

    public function save(ContactIdentity $identity): void;
}

