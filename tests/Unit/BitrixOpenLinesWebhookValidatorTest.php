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
        self::itParsesOnImConnectorMessageAddPayloadAsOpenLineEvent();
        self::itParsesMessagesRootShape();
        self::itParsesStringifiedDataRoot();
        self::itParsesFieldsMessagesShape();
        self::itParsesOnOpenLineMessageAddPayload();
        self::itSkipsOnOpenLineMessageAddFromExternalClient();
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

    private static function itParsesFieldsMessagesShape(): void
    {
        $validator = new BitrixOpenLinesWebhookValidator(ChannelProvider::TELEGRAM);

        $messages = $validator->validate([
            'event' => 'OnImConnectorMessageAdd',
            'data' => [
                'FIELDS' => [
                    'MESSAGES' => [
                        [
                            'chat_id' => 'conversation-77',
                            'message_id' => 'im-77',
                            'text' => 'fields shape',
                            'date' => '1775650525',
                        ],
                    ],
                ],
            ],
        ]);

        Assertions::assertCount(1, $messages);
        Assertions::assertSame('conversation-77', $messages[0]->command->externalThreadId);
        Assertions::assertSame('im-77', $messages[0]->command->externalMessageId);
        Assertions::assertSame('fields shape', $messages[0]->command->body);
    }

    private static function itParsesOnOpenLineMessageAddPayload(): void
    {
        $validator = new BitrixOpenLinesWebhookValidator(ChannelProvider::TELEGRAM);

        $messages = $validator->validate([
            'event' => 'ONOPENLINEMESSAGEADD',
            'eventId' => 1024,
            'data' => [
                'DATA' => [
                    [
                        'connector' => [
                            'connector_id' => 'chat_sync',
                            'line_id' => 192,
                            'chat_id' => 10587,
                            'user_id' => '479493406',
                        ],
                        'chat' => [
                            'id' => 10585,
                        ],
                        'message' => [
                            'id' => 80964,
                            'text' => 'Ответ оператора',
                            'system' => 'N',
                            'user_id' => 17,
                        ],
                    ],
                ],
            ],
        ]);

        Assertions::assertCount(1, $messages);
        Assertions::assertSame('479493406', $messages[0]->command->externalThreadId);
        Assertions::assertSame('80964', $messages[0]->command->externalMessageId);
        Assertions::assertSame('80964', $messages[0]->imMessageId);
        Assertions::assertSame('10587', $messages[0]->imChatId);
    }

    private static function itParsesOnImConnectorMessageAddPayloadAsOpenLineEvent(): void
    {
        $validator = new BitrixOpenLinesWebhookValidator(ChannelProvider::TELEGRAM);

        $messages = $validator->validate([
            'event' => 'ONIMCONNECTORMESSAGEADD',
            'eventId' => 2049,
            'data' => [
                'DATA' => [
                    [
                        'connector' => [
                            'connector_id' => 'chat_sync',
                            'line_id' => 192,
                            'chat_id' => 10587,
                            'user_id' => '479493406',
                        ],
                        'chat' => [
                            'id' => 10585,
                        ],
                        'message' => [
                            'id' => 80966,
                            'text' => 'Ответ оператора через imconnector',
                            'system' => 'N',
                            'user_id' => 17,
                        ],
                    ],
                ],
            ],
        ]);

        Assertions::assertCount(1, $messages);
        Assertions::assertSame('479493406', $messages[0]->command->externalThreadId);
        Assertions::assertSame('80966', $messages[0]->command->externalMessageId);
        Assertions::assertSame('10587', $messages[0]->imChatId);
    }

    private static function itSkipsOnOpenLineMessageAddFromExternalClient(): void
    {
        $validator = new BitrixOpenLinesWebhookValidator(ChannelProvider::TELEGRAM);

        $messages = $validator->validate([
            'event' => 'ONOPENLINEMESSAGEADD',
            'eventId' => 2048,
            'data' => [
                'DATA' => [
                    [
                        'connector' => [
                            'chat_id' => 10587,
                            'user_id' => '479493406',
                        ],
                        'message' => [
                            'id' => 80965,
                            'text' => 'Сообщение клиента',
                            'system' => 'N',
                            'user_id' => '479493406',
                        ],
                    ],
                ],
            ],
        ]);

        Assertions::assertCount(0, $messages);
    }
}
