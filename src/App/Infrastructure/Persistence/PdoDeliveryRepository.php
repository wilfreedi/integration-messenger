<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\Core\Application\Port\Persistence\DeliveryRepository;
use ChatSync\Core\Domain\Model\Delivery;

final class PdoDeliveryRepository extends AbstractPdoRepository implements DeliveryRepository
{
    public function save(Delivery $delivery): void
    {
        $this->execute(
            'INSERT INTO deliveries (id, message_id, system_type, provider, direction, external_id, correlation_id, status, created_at)
             VALUES (:id, :message_id, :system_type, :provider, :direction, :external_id, :correlation_id, :status, :created_at)
             ON CONFLICT (id) DO UPDATE SET
                status = EXCLUDED.status,
                external_id = EXCLUDED.external_id,
                correlation_id = EXCLUDED.correlation_id',
            [
                'id' => $delivery->id()->toString(),
                'message_id' => $delivery->messageId()->toString(),
                'system_type' => $delivery->systemType()->value,
                'provider' => $delivery->provider(),
                'direction' => $delivery->direction()->value,
                'external_id' => $delivery->externalId(),
                'correlation_id' => $delivery->correlationId(),
                'status' => $delivery->status()->value,
                'created_at' => $delivery->createdAt()->format(DATE_ATOM),
            ],
        );
    }
}

