<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Validator;

use ChatSync\Core\Application\Command\SyncInboundChannelMessageCommand;
use ChatSync\Core\Application\Dto\AttachmentData;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\CrmProvider;
use DateTimeImmutable;
use InvalidArgumentException;

final class InboundChannelMessageValidator
{
    /**
     * @param array<string, mixed> $payload
     */
    public function validate(array $payload): SyncInboundChannelMessageCommand
    {
        return new SyncInboundChannelMessageCommand(
            eventId: $this->requiredString($payload, 'event_id'),
            channelProvider: ChannelProvider::from($this->requiredString($payload, 'channel_provider')),
            crmProvider: CrmProvider::from($this->requiredString($payload, 'crm_provider')),
            managerAccountExternalId: $this->requiredString($payload, 'manager_account_external_id'),
            contactExternalChatId: $this->requiredString($payload, 'contact_external_chat_id'),
            contactExternalUserId: $this->optionalString($payload, 'contact_external_user_id'),
            contactDisplayName: $this->requiredString($payload, 'contact_display_name'),
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

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a string or null.', $key));
        }

        return trim($value);
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}

