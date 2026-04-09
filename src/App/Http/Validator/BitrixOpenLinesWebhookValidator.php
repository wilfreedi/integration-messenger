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
        $eventName = strtoupper($this->firstString($payload, [['event'], ['EVENT']]) ?? '');
        $sourceEventId = $this->firstString($payload, [['eventId'], ['EVENT_ID'], ['event_id']]);

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

            if ($this->shouldSkipItem($item, $eventName)) {
                continue;
            }

            $externalThreadId = $this->resolveExternalThreadId($item, $eventName);

            if ($externalThreadId === null || $externalThreadId === '') {
                continue;
            }

            $crmExternalMessageId = $this->messageId($item, $index);
            $imMessageId = $this->firstString($item, [
                ['im', 'message_id'],
                ['im', 'message', 'id'],
                ['message', 'id'],
                ['MESSAGE', 'ID'],
            ]);
            $imChatId = $this->firstString($item, [
                ['im', 'chat_id'],
                ['im', 'chat', 'id'],
                ['connector', 'chat_id'],
                ['connector', 'CHAT_ID'],
                ['chat', 'id'],
                ['CHAT_ID'],
            ]);
            $body = $this->firstString(
                $item,
                [
                    ['message', 'text'],
                    ['message', 'message'],
                    ['text'],
                    ['message_text'],
                    ['MESSAGE'],
                ],
            ) ?? '';
            if ($body === '') {
                $body = '[empty_message]';
            }

            $eventId = sprintf(
                'bitrix-openlines:%s:%d',
                $sourceEventId !== null && $sourceEventId !== ''
                    ? $sourceEventId
                    : ($externalThreadId . ':' . ($imMessageId ?? $crmExternalMessageId)),
                $index,
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

        return $messages;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveExternalThreadId(array $item, string $eventName): ?string
    {
        $connectorExternalUserId = $this->firstString($item, [
            ['connector', 'user_id'],
            ['connector', 'USER_ID'],
        ]);

        if ($this->isOpenLineEvent($eventName) && $connectorExternalUserId !== null && $connectorExternalUserId !== '') {
            return $connectorExternalUserId;
        }

        $chatId = $this->firstString(
            $item,
            [
                ['chat', 'id'],
                ['im', 'chat_id'],
                ['im', 'chat', 'id'],
                ['chat_id'],
                ['CHAT_ID'],
                ['connector', 'chat_id'],
                ['connector', 'CHAT_ID'],
            ]
        );
        if ($chatId !== null && $chatId !== '') {
            return $chatId;
        }

        return $connectorExternalUserId;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function shouldSkipItem(array $item, string $eventName): bool
    {
        $systemFlag = strtoupper($this->firstString(
            $item,
            [
                ['message', 'system'],
                ['MESSAGE', 'SYSTEM'],
            ],
        ) ?? '');
        if ($systemFlag === 'Y') {
            return true;
        }

        if (!$this->isOpenLineEvent($eventName)) {
            return false;
        }

        $connectorExternalUserId = $this->firstString($item, [
            ['connector', 'user_id'],
            ['connector', 'USER_ID'],
        ]);
        $messageUserId = $this->firstString($item, [
            ['message', 'user_id'],
            ['MESSAGE', 'USER_ID'],
        ]);

        return $connectorExternalUserId !== null
            && $connectorExternalUserId !== ''
            && $messageUserId !== null
            && $messageUserId !== ''
            && $connectorExternalUserId === $messageUserId;
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
        foreach ([
            ['DATA'],
            ['MESSAGES'],
            ['FIELDS', 'DATA'],
            ['FIELDS', 'MESSAGES'],
            ['FIELDS'],
        ] as $path) {
            $data = $this->valueByPath($dataRoot, $path);
            if (!is_array($data)) {
                continue;
            }

            if ($this->looksLikeMessageItem($data)) {
                return [$data];
            }

            if ($this->isAssoc($data)) {
                continue;
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

        $fallback = $this->firstString($item, [['message_id'], ['MESSAGE_ID'], ['id'], ['ID']]);
        if ($fallback !== null && $fallback !== '') {
            return $fallback;
        }

        return sprintf('bitrix-message-%d-%d', time(), $index);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function occurredAt(array $item): DateTimeImmutable
    {
        $value = $this->firstString($item, [
            ['message', 'date_create'],
            ['message', 'date'],
            ['date_create'],
            ['date'],
        ]);
        if ($value === null || $value === '') {
            return new DateTimeImmutable();
        }

        if (ctype_digit($value)) {
            try {
                return (new DateTimeImmutable())->setTimestamp((int) $value);
            } catch (\Throwable) {
                return new DateTimeImmutable();
            }
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
            if (is_array($value) && isset($value[0])) {
                $value = $value[0];
            }
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

    /**
     * @param array<int|string, mixed> $item
     */
    private function looksLikeMessageItem(array $item): bool
    {
        return $this->firstString($item, [
            ['chat', 'id'],
            ['im', 'chat_id'],
            ['im', 'chat', 'id'],
            ['chat_id'],
            ['CHAT_ID'],
            ['connector', 'chat_id'],
            ['connector', 'user_id'],
        ]) !== null;
    }

    private function isOpenLineEvent(string $eventName): bool
    {
        if ($eventName === '') {
            return false;
        }

        return in_array($eventName, [
            'ONIMCONNECTORMESSAGEADD',
            'ONOPENLINEMESSAGEADD',
            'ONOPENLINEMESSAGEUPDATE',
            'ONOPENLINEMESSAGEDELETE',
            'ONSENDMESSAGECUSTOM',
        ], true);
    }
}
