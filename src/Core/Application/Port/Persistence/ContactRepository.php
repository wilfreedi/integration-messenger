<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Persistence;

use ChatSync\Core\Domain\Model\Contact;
use ChatSync\Core\Domain\ValueObject\ContactId;

interface ContactRepository
{
    public function findById(ContactId $id): ?Contact;

    public function save(Contact $contact): void;
}

