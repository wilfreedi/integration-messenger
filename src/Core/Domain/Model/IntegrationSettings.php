<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Model;

use ChatSync\Core\Domain\ValueObject\IntegrationSettingsId;
use DateTimeImmutable;

final readonly class IntegrationSettings
{
    public function __construct(
        private IntegrationSettingsId $id,
        private string $ownerType,
        private string $ownerId,
        private string $provider,
        private string $endpointUrl,
        private string $authScheme,
        private string $authToken,
        private bool $isEnabled,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public function id(): IntegrationSettingsId
    {
        return $this->id;
    }

    public function ownerType(): string
    {
        return $this->ownerType;
    }

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function endpointUrl(): string
    {
        return $this->endpointUrl;
    }

    public function authScheme(): string
    {
        return $this->authScheme;
    }

    public function authToken(): string
    {
        return $this->authToken;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

