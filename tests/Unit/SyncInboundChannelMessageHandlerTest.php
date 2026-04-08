<?php

declare(strict_types=1);

namespace ChatSync\Tests\Unit;

use ChatSync\Core\Application\Command\SyncInboundChannelMessageCommand;
use ChatSync\Core\Application\Dto\AttachmentData;
use ChatSync\Core\Application\Handler\SyncInboundChannelMessageHandler;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\CrmProvider;
use ChatSync\Core\Domain\Enum\ManagerAccountStatus;
use ChatSync\Core\Domain\Model\ManagerAccount;
use ChatSync\Core\Domain\ValueObject\ManagerAccountId;
use ChatSync\Core\Domain\ValueObject\ManagerId;
use ChatSync\Tests\Support\Assertions;
use ChatSync\Tests\Support\FixedClock;
use ChatSync\Tests\Support\InMemoryAttachmentRepository;
use ChatSync\Tests\Support\InMemoryContactIdentityRepository;
use ChatSync\Tests\Support\InMemoryContactRepository;
use ChatSync\Tests\Support\InMemoryConversationRepository;
use ChatSync\Tests\Support\InMemoryCrmThreadRepository;
use ChatSync\Tests\Support\InMemoryDeliveryRepository;
use ChatSync\Tests\Support\InMemoryExternalOperationLogger;
use ChatSync\Tests\Support\InMemoryManagerAccountRepository;
use ChatSync\Tests\Support\InMemoryMessageReferenceRepository;
use ChatSync\Tests\Support\InMemoryMessageRepository;
use ChatSync\Tests\Support\InMemoryProcessedEventRepository;
use ChatSync\Tests\Support\SequenceIdGenerator;
use ChatSync\Tests\Support\SpyCrmConnector;
use ChatSync\Tests\Support\StaticCrmConnectorRegistry;
use DateTimeImmutable;

final class SyncInboundChannelMessageHandlerTest
{
    public static function run(): void
    {
        self::itCreatesConversationAndSyncsInboundMessageToCrm();
        self::itSkipsDuplicateInboundExternalMessages();
    }

    private static function itCreatesConversationAndSyncsInboundMessageToCrm(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-04-07T10:00:00+05:00'));
        $ids = new SequenceIdGenerator('inbound');

        $managerAccounts = new InMemoryManagerAccountRepository();
        $contacts = new InMemoryContactRepository();
        $contactIdentities = new InMemoryContactIdentityRepository();
        $conversations = new InMemoryConversationRepository();
        $messages = new InMemoryMessageRepository();
        $attachments = new InMemoryAttachmentRepository();
        $crmThreads = new InMemoryCrmThreadRepository();
        $deliveries = new InMemoryDeliveryRepository();
        $messageReferences = new InMemoryMessageReferenceRepository();
        $processedEvents = new InMemoryProcessedEventRepository();
        $logger = new InMemoryExternalOperationLogger();
        $crmConnector = new SpyCrmConnector();

        $managerAccounts->save(new ManagerAccount(
            ManagerAccountId::fromString('manager-account-1'),
            ManagerId::fromString('manager-1'),
            ChannelProvider::TELEGRAM,
            'telegram-manager-account',
            ManagerAccountStatus::ACTIVE,
            $clock->now(),
        ));

        $handler = new SyncInboundChannelMessageHandler(
            $managerAccounts,
            $contacts,
            $contactIdentities,
            $conversations,
            $messages,
            $attachments,
            $crmThreads,
            $deliveries,
            $messageReferences,
            $processedEvents,
            new StaticCrmConnectorRegistry([CrmProvider::BITRIX->value => $crmConnector]),
            $logger,
            $ids,
            $clock,
        );

        $result = $handler(new SyncInboundChannelMessageCommand(
            eventId: 'telegram-event-1',
            channelProvider: ChannelProvider::TELEGRAM,
            crmProvider: CrmProvider::BITRIX,
            managerAccountExternalId: 'telegram-manager-account',
            contactExternalChatId: 'telegram-chat-42',
            contactExternalUserId: 'telegram-user-7',
            contactDisplayName: 'Alice Example',
            externalMessageId: 'telegram-message-1',
            body: 'Hello from Telegram',
            occurredAt: $clock->now(),
            attachments: [
                new AttachmentData('photo', 'file-1', 'photo.jpg', 'image/jpeg'),
            ],
        ));

        Assertions::assertTrue($result->processed, 'Inbound message must be processed.');
        Assertions::assertSame(1, $crmConnector->ensureThreadCalls, 'CRM thread must be created once.');
        Assertions::assertSame(1, $crmConnector->sendMessageCalls, 'CRM message must be sent once.');
        Assertions::assertCount(1, $contacts->all(), 'One contact is expected.');
        Assertions::assertCount(2, $contactIdentities->all(), 'Chat and user identities are expected.');
        Assertions::assertCount(1, $conversations->all(), 'One conversation is expected.');
        Assertions::assertCount(1, $messages->all(), 'One message is expected.');
        Assertions::assertCount(1, $attachments->all(), 'One attachment is expected.');
        Assertions::assertCount(1, $crmThreads->all(), 'One CRM thread is expected.');
        Assertions::assertCount(1, $deliveries->all(), 'One delivery is expected.');
        Assertions::assertCount(4, $logger->entries(), 'Inbound receive, send attempt, thread create and send success must be logged.');
        $operations = array_map(
            static fn (object $entry): string => $entry->operation,
            $logger->entries(),
        );
        Assertions::assertTrue(in_array('webhook_received', $operations, true), 'Inbound receive log is required.');
        Assertions::assertTrue(in_array('send_message_attempt', $operations, true), 'Bitrix send attempt log is required.');
        Assertions::assertTrue(in_array('ensure_thread', $operations, true), 'Thread creation log is required.');
        Assertions::assertTrue(in_array('send_message', $operations, true), 'Bitrix send success log is required.');
    }

    private static function itSkipsDuplicateInboundExternalMessages(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-04-07T10:00:00+05:00'));
        $ids = new SequenceIdGenerator('inbound-dup');

        $managerAccounts = new InMemoryManagerAccountRepository();
        $contacts = new InMemoryContactRepository();
        $contactIdentities = new InMemoryContactIdentityRepository();
        $conversations = new InMemoryConversationRepository();
        $messages = new InMemoryMessageRepository();
        $attachments = new InMemoryAttachmentRepository();
        $crmThreads = new InMemoryCrmThreadRepository();
        $deliveries = new InMemoryDeliveryRepository();
        $messageReferences = new InMemoryMessageReferenceRepository();
        $processedEvents = new InMemoryProcessedEventRepository();
        $logger = new InMemoryExternalOperationLogger();
        $crmConnector = new SpyCrmConnector();

        $managerAccounts->save(new ManagerAccount(
            ManagerAccountId::fromString('manager-account-2'),
            ManagerId::fromString('manager-2'),
            ChannelProvider::TELEGRAM,
            'telegram-manager-account',
            ManagerAccountStatus::ACTIVE,
            $clock->now(),
        ));

        $handler = new SyncInboundChannelMessageHandler(
            $managerAccounts,
            $contacts,
            $contactIdentities,
            $conversations,
            $messages,
            $attachments,
            $crmThreads,
            $deliveries,
            $messageReferences,
            $processedEvents,
            new StaticCrmConnectorRegistry([CrmProvider::BITRIX->value => $crmConnector]),
            $logger,
            $ids,
            $clock,
        );

        $baseCommand = new SyncInboundChannelMessageCommand(
            eventId: 'telegram-event-1',
            channelProvider: ChannelProvider::TELEGRAM,
            crmProvider: CrmProvider::BITRIX,
            managerAccountExternalId: 'telegram-manager-account',
            contactExternalChatId: 'telegram-chat-42',
            contactExternalUserId: 'telegram-user-7',
            contactDisplayName: 'Alice Example',
            externalMessageId: 'telegram-message-1',
            body: 'Hello from Telegram',
            occurredAt: $clock->now(),
        );

        $firstResult = $handler($baseCommand);
        $duplicateResult = $handler(new SyncInboundChannelMessageCommand(
            eventId: 'telegram-event-2',
            channelProvider: ChannelProvider::TELEGRAM,
            crmProvider: CrmProvider::BITRIX,
            managerAccountExternalId: 'telegram-manager-account',
            contactExternalChatId: 'telegram-chat-42',
            contactExternalUserId: 'telegram-user-7',
            contactDisplayName: 'Alice Example',
            externalMessageId: 'telegram-message-1',
            body: 'Hello from Telegram',
            occurredAt: $clock->now(),
        ));

        Assertions::assertTrue($firstResult->processed, 'The first inbound message must be processed.');
        Assertions::assertFalse($duplicateResult->processed, 'Duplicate inbound message must be skipped.');
        Assertions::assertSame('duplicate_external_message', $duplicateResult->reason);
        Assertions::assertSame(1, $crmConnector->ensureThreadCalls, 'CRM thread must not be recreated.');
        Assertions::assertSame(1, $crmConnector->sendMessageCalls, 'CRM message must not be sent twice.');
        Assertions::assertCount(1, $messages->all(), 'Only one message must be stored.');
        Assertions::assertCount(1, $deliveries->all(), 'Only one delivery must be stored.');
    }
}
