<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use ChatSync\Core\Application\Port\Persistence\AttachmentRepository;
use ChatSync\Core\Domain\Model\Attachment;

final class PdoAttachmentRepository extends AbstractPdoRepository implements AttachmentRepository
{
    public function save(Attachment $attachment): void
    {
        $this->execute(
            'INSERT INTO attachments (id, message_id, attachment_type, external_file_id, file_name, mime_type, created_at)
             VALUES (:id, :message_id, :attachment_type, :external_file_id, :file_name, :mime_type, :created_at)
             ON CONFLICT (id) DO UPDATE SET
                attachment_type = EXCLUDED.attachment_type,
                external_file_id = EXCLUDED.external_file_id,
                file_name = EXCLUDED.file_name,
                mime_type = EXCLUDED.mime_type',
            [
                'id' => $attachment->id()->toString(),
                'message_id' => $attachment->messageId()->toString(),
                'attachment_type' => $attachment->type(),
                'external_file_id' => $attachment->externalFileId(),
                'file_name' => $attachment->fileName(),
                'mime_type' => $attachment->mimeType(),
                'created_at' => $attachment->createdAt()->format(DATE_ATOM),
            ],
        );
    }
}

