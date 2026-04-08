<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Validator;

use ChatSync\App\Http\Dto\BitrixOpenLinesOperatorMessage;
use ChatSync\Core\Application\Command\SyncOutboundCrmMessageCommand;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\CrmProvider;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BitrixOpenLinesWebhookValidator
{
    public function __construct(private ChannelProvider $defaultChannelProvider)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<BitrixOpenLinesOperatorMessage>
     */
    public function validate(array $payload): array
    {
        $dataRoot = $this->dataRoot($payload);
        $items = $this->items($dataRoot);

        if ($items === []) {
            throw new InvalidArgumentException('Bitrix webhook does not contain DATA or MESSAGES items.');
        }

        $messages = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $externalThreadId = $this->firstString(
                $item,
                [['chat', 'id'], ['im', 'chat_id'], ['im', 'chat', 'id']]
            );

            if ($externalThreadId === null || $externalThreadId === '') {
                continue;
            }

            $crmExternalMessageId = $this->messageId($item, $index);
            $imMessageId = $this->firstString($item, [['im', 'message_id']]);
            $imChatId = $this->firstString($item, [['im', 'chat_id'], ['im', 'chat', 'id']]);
            $body = $this->firstString($item, [['message', 'text'], ['message', 'message']]) ?? '';
            if ($body === '') {
                $body = '[empty_message]';
            }

            $eventId = sprintf(
                'bitrix-openlines:%s:%s:%d',
                $externalThreadId,
                $imMessageId ?? $crmExternalMessageId,
                $index
            );

            $messages[] = new BitrixOpenLinesOperatorMessage(
                command: new SyncOutboundCrmMessageCommand(
                    eventId: $eventId,
                    crmProvider: CrmProvider::BITRIX,
                    channelProvider: $this->defaultChannelProvider,
                    externalThreadId: $externalThreadId,
                    externalMessageId: $crmExternalMessageId,
                    body: $body,
                    occurredAt: $this->occurredAt($item),
                    attachments: [],
                ),
                imMessageId: $imMessageId,
                imChatId: $imChatId,
            );
        }

        if ($messages === []) {
            throw new InvalidArgumentException('Bitrix webhook items do not contain required chat/message fields.');
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function dataRoot(array $payload): array
    {
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            return $data;
        }
        if (is_string($data) && trim($data) !== '') {
            try {
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $dataRoot
     * @return list<mixed>
     */
    private function items(array $dataRoot): array
    {
        foreach (['DATA', 'MESSAGES'] as $key) {
            $data = $dataRoot[$key] ?? null;
            if (!is_array($data)) {
                continue;
            }

            if ($this->isAssoc($data)) {
                return [$data];
            }

            return array_values($data);
        }

        return [];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function messageId(array $item, int $index): string
    {
        $value = $this->valueByPath($item, ['message', 'id']);
        if (is_array($value) && isset($value[0])) {
            $first = $value[0];
            if (is_string($first) && $first !== '') {
                return $first;
            }
            if (is_int($first)) {
                return (string) $first;
            }
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }

        $imMessageId = $this->firstString($item, [['im', 'message_id']]);
        if ($imMessageId !== null && $imMessageId !== '') {
            return $imMessageId;
        }

        return sprintf('bitrix-message-%d-%d', time(), $index);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function occurredAt(array $item): DateTimeImmutable
    {
        $value = $this->firstString($item, [['message', 'date_create'], ['message', 'date']]);
        if ($value === null || $value === '') {
            return new DateTimeImmutable();
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return new DateTimeImmutable();
        }
    }

    /**
     * @param array<string, mixed> $root
     * @param list<list<int|string>> $paths
     */
    private function firstString(array $root, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->valueByPath($root, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $root
     * @param list<int|string> $path
     */
    private function valueByPath(array $root, array $path): mixed
    {
        $value = $root;
        foreach ($path as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
