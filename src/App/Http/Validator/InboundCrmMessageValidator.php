<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Validator;

use ChatSync\Core\Application\Command\SyncOutboundCrmMessageCommand;
use ChatSync\Core\Application\Dto\AttachmentData;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\CrmProvider;
use DateTimeImmutable;
use InvalidArgumentException;

final class InboundCrmMessageValidator
{
    /**
     * @param array<string, mixed> $payload
     */
    public function validate(array $payload): SyncOutboundCrmMessageCommand
    {
        return new SyncOutboundCrmMessageCommand(
            eventId: $this->requiredString($payload, 'event_id'),
            crmProvider: CrmProvider::from($this->requiredString($payload, 'crm_provider')),
            channelProvider: ChannelProvider::from($this->requiredString($payload, 'channel_provider')),
            externalThreadId: $this->requiredString($payload, 'external_thread_id'),
            externalMessageId: $this->requiredString($payload, 'external_message_id'),
            body: $this->requiredString($payload, 'body'),
            occurredAt: $this->dateTime($this->requiredString($payload, 'occurred_at')),
            attachments: $this->attachments($payload['attachments'] ?? []),
        );
    }

    /**
     * @param mixed $value
     * @return list<AttachmentData>
     */
    private function attachments(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('Field "attachments" must be an array.');
        }

        $attachments = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Each attachment must be an object.');
            }

            $attachments[] = new AttachmentData(
                $this->requiredString($item, 'type'),
                $this->requiredString($item, 'external_file_id'),
                $this->requiredString($item, 'file_name'),
                $this->requiredString($item, 'mime_type'),
            );
        }

        return $attachments;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" is required and must be a non-empty string.', $key));
        }

        return trim($value);
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}

