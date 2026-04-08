<?php

declare(strict_types=1);

namespace ChatSync\Core\Domain\Model;

use ChatSync\Core\Domain\ValueObject\AttachmentId;
use ChatSync\Core\Domain\ValueObject\MessageId;
use DateTimeImmutable;

final readonly class Attachment
{
    public function __construct(
        private AttachmentId $id,
        private MessageId $messageId,
        private string $type,
        private string $externalFileId,
        private string $fileName,
        private string $mimeType,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): AttachmentId
    {
        return $this->id;
    }

    public function messageId(): MessageId
    {
        return $this->messageId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function externalFileId(): string
    {
        return $this->externalFileId;
    }

    public function fileName(): string
    {
        return $this->fileName;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

