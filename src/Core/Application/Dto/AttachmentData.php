<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Dto;

final readonly class AttachmentData
{
    public function __construct(
        public string $type,
        public string $externalFileId,
        public string $fileName,
        public string $mimeType,
    ) {
    }
}

