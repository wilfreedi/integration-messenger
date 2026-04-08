<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Model;

use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\ManagerAccountStatus;
use ChatSync\Core\Domain\ValueObject\ManagerAccountId;
use ChatSync\Core\Domain\ValueObject\ManagerId;
use DateTimeImmutable;

final readonly class ManagerAccount
{
    public function __construct(
        private ManagerAccountId $id,
        private ManagerId $managerId,
        private ChannelProvider $channelProvider,
        private string $externalAccountId,
        private ManagerAccountStatus $status,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): ManagerAccountId
    {
        return $this->id;
    }

    public function managerId(): ManagerId
    {
        return $this->managerId;
    }

    public function channelProvider(): ChannelProvider
    {
        return $this->channelProvider;
    }

    public function externalAccountId(): string
    {
        return $this->externalAccountId;
    }

    public function status(): ManagerAccountStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

