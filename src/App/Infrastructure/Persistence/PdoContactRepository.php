<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\Core\Application\Port\Persistence\ContactRepository;
use ChatSync\Core\Domain\Model\Contact;
use ChatSync\Core\Domain\ValueObject\ContactId;

final class PdoContactRepository extends AbstractPdoRepository implements ContactRepository
{
    public function findById(ContactId $id): ?Contact
    {
        $row = $this->execute(
            'SELECT * FROM contacts WHERE id = :id LIMIT 1',
            ['id' => $id->toString()],
        )->fetch();

        return $row === false ? null : $this->map($row);
    }

    public function save(Contact $contact): void
    {
        $this->execute(
            'INSERT INTO contacts (id, display_name, primary_phone, created_at)
             VALUES (:id, :display_name, :primary_phone, :created_at)
             ON CONFLICT (id) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                primary_phone = EXCLUDED.primary_phone',
            [
                'id' => $contact->id()->toString(),
                'display_name' => $contact->displayName(),
                'primary_phone' => $contact->primaryPhone(),
                'created_at' => $contact->createdAt()->format(DATE_ATOM),
            ],
        );
    }

    /**
     * @param array<string, string|null> $row
     */
    private function map(array $row): Contact
    {
        return new Contact(
            ContactId::fromString($row['id']),
            $row['display_name'] ?? '',
            $row['primary_phone'],
            $this->dateTime((string) $row['created_at']),
        );
    }
}

