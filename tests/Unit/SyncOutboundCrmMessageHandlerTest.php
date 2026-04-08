<?php

declare(strict_types=1);

namespace ChatSync\Tests\Unit;

use ChatSync\Core\Application\Command\SyncOutboundCrmMessageCommand;
use ChatSync\Core\Application\Dto\AttachmentData;
use ChatSync\Core\Application\Handler\SyncOutboundCrmMessageHandler;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\ContactIdentityType;
use ChatSync\Core\Domain\Enum\ConversationStatus;
use ChatSync\Core\Domain\Enum\CrmProvider;
use ChatSync\Core\Domain\Enum\ManagerAccountStatus;
use ChatSync\Core\Domain\Model\Contact;
use ChatSync\Core\Domain\Model\ContactIdentity;
use ChatSync\Core\Domain\Model\Conversation;
use ChatSync\Core\Domain\Model\CRMThread;
use ChatSync\Core\Domain\Model\ManagerAccount;
use ChatSync\Core\Domain\ValueObject\ContactId;
use ChatSync\Core\Domain\ValueObject\ContactIdentityId;
use ChatSync\Core\Domain\ValueObject\ConversationId;
use ChatSync\Core\Domain\ValueObject\CrmThreadId;
use ChatSync\Core\Domain\ValueObject\ManagerAccountId;
use ChatSync\Core\Domain\ValueObject\ManagerId;
use ChatSync\Tests\Support\Assertions;
use ChatSync\Tests\Support\FixedClock;
use ChatSync\Tests\Support\InMemoryAttachmentRepository;
use ChatSync\Tests\Support\InMemoryContactIdentityRepository;
use ChatSync\Tests\Support\InMemoryConversationRepository;
use ChatSync\Tests\Support\InMemoryCrmThreadRepository;
use ChatSync\Tests\Support\InMemoryDeliveryRepository;
use ChatSync\Tests\Support\InMemoryExternalOperationLogger;
use ChatSync\Tests\Support\InMemoryManagerAccountRepository;
use ChatSync\Tests\Support\InMemoryMessageReferenceRepository;
use ChatSync\Tests\Support\InMemoryMessageRepository;
use ChatSync\Tests\Support\InMemoryProcessedEventRepository;
use ChatSync\Tests\Support\SequenceIdGenerator;
use ChatSync\Tests\Support\SpyChannelConnector;
use ChatSync\Tests\Support\StaticChannelConnectorRegistry;
use DateTimeImmutable;

final class SyncOutboundCrmMessageHandlerTest
{
    public static function run(): void
    {
        self::itSendsOutboundCrmRepliesToChannel();
        self::itResolvesCrmThreadByChannelChatFallback();
        self::itSkipsDuplicateOutboundExternalMessages();
    }

    private static function itSendsOutboundCrmRepliesToChannel(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-04-07T11:00:00+05:00'));
        $ids = new SequenceIdGenerator('outbound');

        $managerAccounts = new InMemoryManagerAccountRepository();
        $contactIdentities = new InMemoryContactIdentityRepository();
        $conversations = new InMemoryConversationRepository();
        $crmThreads = new InMemoryCrmThreadRepository();
        $messages = new InMemoryMessageRepository();
        $attachments = new InMemoryAttachmentRepository();
        $deliveries = new InMemoryDeliveryRepository();
        $messageReferences = new InMemoryMessageReferenceRepository();
        $processedEvents = new InMemoryProcessedEventRepository();
        $logger = new InMemoryExternalOperationLogger();
        $channelConnector = new SpyChannelConnector();

        $managerAccount = new ManagerAccount(
            ManagerAccountId::fromString('manager-account-3'),
            ManagerId::fromString('manager-3'),
            ChannelProvider::TELEGRAM,
            'telegram-manager-account',
            ManagerAccountStatus::ACTIVE,
            $clock->now(),
        );
        $managerAccounts->save($managerAccount);

        $contactId = ContactId::fromString('contact-1');
        $conversation = new Conversation(
            ConversationId::fromString('conversation-1'),
            $managerAccount->id(),
            $contactId,
            ConversationStatus::OPEN,
            $clock->now(),
            $clock->now(),
        );
        $conversations->save($conversation);

        $contactIdentities->save(new ContactIdentity(
            ContactIdentityId::fromString('contact-identity-1'),
            $contactId,
            ChannelProvider::TELEGRAM->value,
            ContactIdentityType::CHANNEL_CHAT_ID,
            'telegram-chat-42',
            true,
            $clock->now(),
        ));

        $crmThreads->save(new CRMThread(
            CrmThreadId::fromString('crm-thread-1'),
            $conversation->id(),
            CrmProvider::BITRIX,
            'bitrix-thread-1',
            $clock->now(),
        ));

        $handler = new SyncOutboundCrmMessageHandler(
            $crmThreads,
            $conversations,
            $managerAccounts,
            $contactIdentities,
            $messages,
            $attachments,
            $deliveries,
            $messageReferences,
            $processedEvents,
            new StaticChannelConnectorRegistry([ChannelProvider::TELEGRAM->value => $channelConnector]),
            $logger,
            $ids,
            $clock,
        );

        $result = $handler(new SyncOutboundCrmMessageCommand(
            eventId: 'bitrix-event-1',
            crmProvider: CrmProvider::BITRIX,
            channelProvider: ChannelProvider::TELEGRAM,
            externalThreadId: 'bitrix-thread-1',
            externalMessageId: 'bitrix-message-1',
            body: 'Reply from Bitrix',
            occurredAt: $clock->now(),
            attachments: [
                new AttachmentData('file', 'file-2', 'reply.pdf', 'application/pdf'),
            ],
        ));

        Assertions::assertTrue($result->processed, 'Outbound CRM message must be processed.');
        Assertions::assertSame(1, $channelConnector->sendMessageCalls, 'Channel send must happen once.');
        Assertions::assertCount(1, $messages->all(), 'One outbound message is expected.');
        Assertions::assertCount(1, $attachments->all(), 'One attachment is expected.');
        Assertions::assertCount(1, $deliveries->all(), 'One delivery is expected.');
        Assertions::assertCount(1, $logger->entries(), 'Channel send must be logged.');
    }

    private static function itSkipsDuplicateOutboundExternalMessages(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-04-07T11:00:00+05:00'));
        $ids = new SequenceIdGenerator('outbound-dup');

        $managerAccounts = new InMemoryManagerAccountRepository();
        $contactIdentities = new InMemoryContactIdentityRepository();
        $conversations = new InMemoryConversationRepository();
        $crmThreads = new InMemoryCrmThreadRepository();
        $messages = new InMemoryMessageRepository();
        $attachments = new InMemoryAttachmentRepository();
        $deliveries = new InMemoryDeliveryRepository();
        $messageReferences = new InMemoryMessageReferenceRepository();
        $processedEvents = new InMemoryProcessedEventRepository();
        $logger = new InMemoryExternalOperationLogger();
        $channelConnector = new SpyChannelConnector();

        $managerAccount = new ManagerAccount(
            ManagerAccountId::fromString('manager-account-4'),
            ManagerId::fromString('manager-4'),
            ChannelProvider::TELEGRAM,
            'telegram-manager-account',
            ManagerAccountStatus::ACTIVE,
            $clock->now(),
        );
        $managerAccounts->save($managerAccount);

        $contactId = ContactId::fromString('contact-2');
        $conversations->save(new Conversation(
            ConversationId::fromString('conversation-2'),
            $managerAccount->id(),
            $contactId,
            ConversationStatus::OPEN,
            $clock->now(),
            $clock->now(),
        ));

        $contactIdentities->save(new ContactIdentity(
            ContactIdentityId::fromString('contact-identity-2'),
            $contactId,
            ChannelProvider::TELEGRAM->value,
            ContactIdentityType::CHANNEL_CHAT_ID,
            'telegram-chat-43',
            true,
            $clock->now(),
        ));

        $crmThreads->save(new CRMThread(
            CrmThreadId::fromString('crm-thread-2'),
            ConversationId::fromString('conversation-2'),
            CrmProvider::BITRIX,
            'bitrix-thread-2',
            $clock->now(),
        ));

        $handler = new SyncOutboundCrmMessageHandler(
            $crmThreads,
            $conversations,
            $managerAccounts,
            $contactIdentities,
            $messages,
            $attachments,
            $deliveries,
            $messageReferences,
            $processedEvents,
            new StaticChannelConnectorRegistry([ChannelProvider::TELEGRAM->value => $channelConnector]),
            $logger,
            $ids,
            $clock,
        );

        $firstResult = $handler(new SyncOutboundCrmMessageCommand(
            eventId: 'bitrix-event-1',
            crmProvider: CrmProvider::BITRIX,
            channelProvider: ChannelProvider::TELEGRAM,
            externalThreadId: 'bitrix-thread-2',
            externalMessageId: 'bitrix-message-1',
            body: 'Reply from Bitrix',
            occurredAt: $clock->now(),
        ));

        $duplicateResult = $handler(new SyncOutboundCrmMessageCommand(
            eventId: 'bitrix-event-2',
            crmProvider: CrmProvider::BITRIX,
            channelProvider: ChannelProvider::TELEGRAM,
            externalThreadId: 'bitrix-thread-2',
            externalMessageId: 'bitrix-message-1',
            body: 'Reply from Bitrix',
            occurredAt: $clock->now(),
        ));

        Assertions::assertTrue($firstResult->processed, 'The first outbound message must be processed.');
        Assertions::assertFalse($duplicateResult->processed, 'Duplicate outbound message must be skipped.');
        Assertions::assertSame('duplicate_external_message', $duplicateResult->reason);
        Assertions::assertSame(1, $channelConnector->sendMessageCalls, 'Channel message must not be sent twice.');
        Assertions::assertCount(1, $messages->all(), 'Only one outbound message must be stored.');
        Assertions::assertCount(1, $deliveries->all(), 'Only one outbound delivery must be stored.');
    }

    private static function itResolvesCrmThreadByChannelChatFallback(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-04-07T11:00:00+05:00'));
        $ids = new SequenceIdGenerator('outbound-fallback');

        $managerAccounts = new InMemoryManagerAccountRepository();
        $contactIdentities = new InMemoryContactIdentityRepository();
        $conversations = new InMemoryConversationRepository();
        $crmThreads = new InMemoryCrmThreadRepository();
        $messages = new InMemoryMessageRepository();
        $attachments = new InMemoryAttachmentRepository();
        $deliveries = new InMemoryDeliveryRepository();
        $messageReferences = new InMemoryMessageReferenceRepository();
        $processedEvents = new InMemoryProcessedEventRepository();
        $logger = new InMemoryExternalOperationLogger();
        $channelConnector = new SpyChannelConnector();

        $managerAccount = new ManagerAccount(
            ManagerAccountId::fromString('manager-account-5'),
            ManagerId::fromString('manager-5'),
            ChannelProvider::TELEGRAM,
            'telegram-manager-account',
            ManagerAccountStatus::ACTIVE,
            $clock->now(),
        );
        $managerAccounts->save($managerAccount);

        $contactId = ContactId::fromString('contact-3');
        $conversation = new Conversation(
            ConversationId::fromString('conversation-3'),
            $managerAccount->id(),
            $contactId,
            ConversationStatus::OPEN,
            $clock->now(),
            $clock->now(),
        );
        $conversations->save($conversation);

        $contactIdentities->save(new ContactIdentity(
            ContactIdentityId::fromString('contact-identity-3'),
            $contactId,
            ChannelProvider::TELEGRAM->value,
            ContactIdentityType::CHANNEL_CHAT_ID,
            'telegram-chat-44',
            true,
            $clock->now(),
        ));

        $crmThreads->save(new CRMThread(
            CrmThreadId::fromString('crm-thread-3'),
            $conversation->id(),
            CrmProvider::BITRIX,
            'bitrix-thread-conversation-3',
            $clock->now(),
        ));

        $handler = new SyncOutboundCrmMessageHandler(
            $crmThreads,
            $conversations,
            $managerAccounts,
            $contactIdentities,
            $messages,
            $attachments,
            $deliveries,
            $messageReferences,
            $processedEvents,
            new StaticChannelConnectorRegistry([ChannelProvider::TELEGRAM->value => $channelConnector]),
            $logger,
            $ids,
            $clock,
        );

        $result = $handler(new SyncOutboundCrmMessageCommand(
            eventId: 'bitrix-event-fallback-1',
            crmProvider: CrmProvider::BITRIX,
            channelProvider: ChannelProvider::TELEGRAM,
            externalThreadId: 'telegram-chat-44',
            externalMessageId: 'bitrix-message-fallback-1',
            body: 'Reply from Bitrix via fallback',
            occurredAt: $clock->now(),
        ));

        Assertions::assertTrue($result->processed, 'Outbound message must be processed by fallback thread resolution.');
        Assertions::assertSame(1, $channelConnector->sendMessageCalls, 'Channel send must happen once.');
        Assertions::assertCount(1, $messages->all(), 'One outbound message is expected.');
    }
}
