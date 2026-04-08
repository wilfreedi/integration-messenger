<?php

declare(strict_types=1);

namespace ChatSync\Tests\Unit;

use ChatSync\App\Http\Validator\BitrixOpenLinesWebhookValidator;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Tests\Support\Assertions;

final class BitrixOpenLinesWebhookValidatorTest
{
    public static function run(): void
    {
        self::itParsesBitrixOpenLinesPayloadToCommands();
        self::itParsesMessagesRootShape();
        self::itParsesStringifiedDataRoot();
    }

    private static function itParsesBitrixOpenLinesPayloadToCommands(): void
    {
        $validator = new BitrixOpenLinesWebhookValidator(ChannelProvider::TELEGRAM);

        $messages = $validator->validate([
            'event' => 'OnSendMessageCustom',
            'data' => [
                'CONNECTOR' => 'chat_sync',
                'LINE' => '7',
                'DATA' => [
                    [
                        'im' => [
                            'chat_id' => 'conversation-42',
                            'message_id' => 'im-1001',
                        ],
                        'chat' => [
                            'id' => 'conversation-42',
                        ],
                        'message' => [
                            'id' => ['bitrix-msg-1'],
                            'date_create' => '2026-04-07T16:15:00+05:00',
                            'text' => 'Ответ менеджера из Битрикс',
                        ],
                    ],
                ],
            ],
        ]);

        Assertions::assertCount(1, $messages);

        $item = $messages[0];
        $command = $item->command;

        Assertions::assertSame('conversation-42', $command->externalThreadId);
        Assertions::assertSame('bitrix-msg-1', $command->externalMessageId);
        Assertions::assertSame('Ответ менеджера из Битрикс', $command->body);
        Assertions::assertSame(ChannelProvider::TELEGRAM, $command->channelProvider);
        Assertions::assertSame('im-1001', $item->imMessageId);
        Assertions::assertSame('conversation-42', $item->imChatId);
    }

    private static function itParsesMessagesRootShape(): void
    {
        $validator = new BitrixOpenLinesWebhookValidator(ChannelProvider::TELEGRAM);

        $messages = $validator->validate([
            'event' => 'OnSendMessageCustom',
            'data' => [
                'MESSAGES' => [
                    [
                        'im' => [
                            'chat_id' => 'conversation-99',
                            'message_id' => 'im-9090',
                        ],
                        'message' => [
                            'id' => ['bitrix-msg-99'],
                            'text' => 'shape MESSAGES',
                        ],
                    ],
                ],
            ],
        ]);

        Assertions::assertCount(1, $messages);
        Assertions::assertSame('conversation-99', $messages[0]->command->externalThreadId);
        Assertions::assertSame('bitrix-msg-99', $messages[0]->command->externalMessageId);
        Assertions::assertSame('im-9090', $messages[0]->imMessageId);
        Assertions::assertSame('conversation-99', $messages[0]->imChatId);
    }

    private static function itParsesStringifiedDataRoot(): void
    {
        $validator = new BitrixOpenLinesWebhookValidator(ChannelProvider::TELEGRAM);

        $messages = $validator->validate([
            'event' => 'OnSendMessageCustom',
            'data' => json_encode([
                'DATA' => [
                    [
                        'im' => [
                            'chat_id' => 'conversation-333',
                            'message_id' => 'im-333',
                        ],
                        'message' => [
                            'id' => ['bitrix-msg-333'],
                            'text' => 'stringified data root',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        Assertions::assertCount(1, $messages);
        Assertions::assertSame('conversation-333', $messages[0]->command->externalThreadId);
        Assertions::assertSame('bitrix-msg-333', $messages[0]->command->externalMessageId);
    }
}
