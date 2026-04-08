<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Persistence;

use ChatSync\Core\Domain\Model\Attachment;

interface AttachmentRepository
{
    public function save(Attachment $attachment): void;
}

