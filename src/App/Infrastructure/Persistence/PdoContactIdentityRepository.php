<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\Core\Application\Port\Persistence\ContactIdentityRepository;
use ChatSync\Core\Domain\Enum\ContactIdentityType;
use ChatSync\Core\Domain\Model\ContactIdentity;
use ChatSync\Core\Domain\ValueObject\ContactId;
use ChatSync\Core\Domain\ValueObject\ContactIdentityId;

final class PdoContactIdentityRepository extends AbstractPdoRepository implements ContactIdentityRepository
{
    public function findByProviderTypeAndValue(string $provider, ContactIdentityType $type, string $value): ?ContactIdentity
    {
        $row = $this->execute(
            'SELECT * FROM contact_identities
             WHERE provider = :provider AND identity_type = :identity_type AND identity_value = :identity_value
             LIMIT 1',
            [
                'provider' => $provider,
                'identity_type' => $type->value,
                'identity_value' => $value,
            ],
        )->fetch();

        return $row === false ? null : $this->map($row);
    }

    public function findPrimaryForContactAndProvider(
        ContactId $contactId,
        string $provider,
        ContactIdentityType $type,
    ): ?ContactIdentity {
        $row = $this->execute(
            'SELECT * FROM contact_identities
             WHERE contact_id = :contact_id AND provider = :provider AND identity_type = :identity_type AND is_primary = TRUE
             LIMIT 1',
            [
                'contact_id' => $contactId->toString(),
                'provider' => $provider,
                'identity_type' => $type->value,
            ],
        )->fetch();

        return $row === false ? null : $this->map($row);
    }

    public function save(ContactIdentity $identity): void
    {
        $this->execute(
            'INSERT INTO contact_identities (id, contact_id, provider, identity_type, identity_value, is_primary, created_at)
             VALUES (:id, :contact_id, :provider, :identity_type, :identity_value, :is_primary, :created_at)
             ON CONFLICT (id) DO UPDATE SET
                contact_id = EXCLUDED.contact_id,
                provider = EXCLUDED.provider,
                identity_type = EXCLUDED.identity_type,
                identity_value = EXCLUDED.identity_value,
                is_primary = EXCLUDED.is_primary',
            [
                'id' => $identity->id()->toString(),
                'contact_id' => $identity->contactId()->toString(),
                'provider' => $identity->provider(),
                'identity_type' => $identity->type()->value,
                'identity_value' => $identity->value(),
                'is_primary' => $identity->isPrimary() ? 'true' : 'false',
                'created_at' => $identity->createdAt()->format(DATE_ATOM),
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): ContactIdentity
    {
        return new ContactIdentity(
            ContactIdentityId::fromString((string) $row['id']),
            ContactId::fromString((string) $row['contact_id']),
            (string) $row['provider'],
            ContactIdentityType::from((string) $row['identity_type']),
            (string) $row['identity_value'],
            (bool) $row['is_primary'],
            $this->dateTime((string) $row['created_at']),
        );
    }
}
