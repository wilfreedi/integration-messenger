<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Model;

use ChatSync\Core\Domain\Enum\DeliveryStatus;
use ChatSync\Core\Domain\Enum\ExternalSystemType;
use ChatSync\Core\Domain\Enum\IntegrationDirection;
use ChatSync\Core\Domain\ValueObject\DeliveryId;
use ChatSync\Core\Domain\ValueObject\MessageId;
use DateTimeImmutable;

final readonly class Delivery
{
    public function __construct(
        private DeliveryId $id,
        private MessageId $messageId,
        private ExternalSystemType $systemType,
        private string $provider,
        private IntegrationDirection $direction,
        private string $externalId,
        private string $correlationId,
        private DeliveryStatus $status,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): DeliveryId
    {
        return $this->id;
    }

    public function messageId(): MessageId
    {
        return $this->messageId;
    }

    public function systemType(): ExternalSystemType
    {
        return $this->systemType;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function direction(): IntegrationDirection
    {
        return $this->direction;
    }

    public function externalId(): string
    {
        return $this->externalId;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function status(): DeliveryStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

