<?php

declare(strict_types=1);

namespace ChatSync\Tests\Support;

use ChatSync\Core\Application\Port\Connector\ChannelConnector;
use ChatSync\Core\Application\Port\Connector\ChannelConnectorRegistry;
use ChatSync\Core\Application\Port\Connector\CrmConnector;
use ChatSync\Core\Application\Port\Connector\CrmConnectorRegistry;
use ChatSync\Core\Application\Port\Connector\OpenCrmThreadRequest;
use ChatSync\Core\Application\Port\Connector\OpenCrmThreadResult;
use ChatSync\Core\Application\Port\Connector\SendChannelMessageRequest;
use ChatSync\Core\Application\Port\Connector\SendChannelMessageResult;
use ChatSync\Core\Application\Port\Connector\SendCrmMessageRequest;
use ChatSync\Core\Application\Port\Connector\SendCrmMessageResult;
use ChatSync\Core\Application\Port\Logging\ExternalOperationLogEntry;
use ChatSync\Core\Application\Port\Logging\ExternalOperationLogger;
use ChatSync\Core\Application\Port\Persistence\AttachmentRepository;
use ChatSync\Core\Application\Port\Persistence\ContactIdentityRepository;
use ChatSync\Core\Application\Port\Persistence\ContactRepository;
use ChatSync\Core\Application\Port\Persistence\ConversationRepository;
use ChatSync\Core\Application\Port\Persistence\CrmThreadRepository;
use ChatSync\Core\Application\Port\Persistence\DeliveryRepository;
use ChatSync\Core\Application\Port\Persistence\ManagerAccountRepository;
use ChatSync\Core\Application\Port\Persistence\MessageReferenceRepository;
use ChatSync\Core\Application\Port\Persistence\MessageRepository;
use ChatSync\Core\Application\Port\Persistence\ProcessedEventRepository;
use ChatSync\Core\Domain\Enum\ChannelProvider;
use ChatSync\Core\Domain\Enum\ContactIdentityType;
use ChatSync\Core\Domain\Enum\CrmProvider;
use ChatSync\Core\Domain\Model\Attachment;
use ChatSync\Core\Domain\Model\Contact;
use ChatSync\Core\Domain\Model\ContactIdentity;
use ChatSync\Core\Domain\Model\Conversation;
use ChatSync\Core\Domain\Model\CRMThread;
use ChatSync\Core\Domain\Model\Delivery;
use ChatSync\Core\Domain\Model\ManagerAccount;
use ChatSync\Core\Domain\Model\Message;
use ChatSync\Core\Domain\ValueObject\ContactId;
use ChatSync\Core\Domain\ValueObject\ConversationId;
use ChatSync\Core\Domain\ValueObject\ManagerAccountId;
use ChatSync\Core\Domain\ValueObject\MessageId;
use ChatSync\Shared\Domain\Clock;
use ChatSync\Shared\Domain\IdGenerator;
use DateTimeImmutable;
use RuntimeException;

final class Assertions
{
    public static function assertTrue(bool $condition, string $message = 'Expected condition to be true.'): void
    {
        if ($condition === false) {
            throw new RuntimeException($message);
        }
    }

    public static function assertFalse(bool $condition, string $message = 'Expected condition to be false.'): void
    {
        if ($condition === true) {
            throw new RuntimeException($message);
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $details = $message !== '' ? $message . ' ' : '';
            throw new RuntimeException($details . sprintf('Expected %s, got %s.', var_export($expected, true), var_export($actual, true)));
        }
    }

    /**
     * @param array<int|string, mixed> $items
     */
    public static function assertCount(int $expectedCount, array $items, string $message = ''): void
    {
        self::assertSame($expectedCount, count($items), $message);
    }
}

final class FixedClock implements Clock
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class SequenceIdGenerator implements IdGenerator
{
    private int $counter = 0;

    public function __construct(private readonly string $prefix = 'test')
    {
    }

    public function next(): string
    {
        $this->counter++;

        return sprintf('%s-%04d', $this->prefix, $this->counter);
    }
}

final class InMemoryManagerAccountRepository implements ManagerAccountRepository
{
    /** @var array<string, ManagerAccount> */
    private array $items = [];

    public function findById(ManagerAccountId $id): ?ManagerAccount
    {
        return $this->items[$id->toString()] ?? null;
    }

    public function findByProviderAndExternalAccountId(
        ChannelProvider $provider,
        string $externalAccountId,
    ): ?ManagerAccount {
        foreach ($this->items as $item) {
            if ($item->channelProvider() === $provider && $item->externalAccountId() === $externalAccountId) {
                return $item;
            }
        }

        return null;
    }

    public function save(ManagerAccount $managerAccount): void
    {
        $this->items[$managerAccount->id()->toString()] = $managerAccount;
    }
}

final class InMemoryContactRepository implements ContactRepository
{
    /** @var array<string, Contact> */
    private array $items = [];

    public function findById(ContactId $id): ?Contact
    {
        return $this->items[$id->toString()] ?? null;
    }

    public function save(Contact $contact): void
    {
        $this->items[$contact->id()->toString()] = $contact;
    }

    /** @return list<Contact> */
    public function all(): array
    {
        return array_values($this->items);
    }
}

final class InMemoryContactIdentityRepository implements ContactIdentityRepository
{
    /** @var array<string, ContactIdentity> */
    private array $items = [];

    public function findByProviderTypeAndValue(string $provider, ContactIdentityType $type, string $value): ?ContactIdentity
    {
        foreach ($this->items as $item) {
            if ($item->provider() === $provider && $item->type() === $type && $item->value() === $value) {
                return $item;
            }
        }

        return null;
    }

    public function findPrimaryForContactAndProvider(
        ContactId $contactId,
        string $provider,
        ContactIdentityType $type,
    ): ?ContactIdentity {
        foreach ($this->items as $item) {
            if (
                $item->contactId()->toString() === $contactId->toString()
                && $item->provider() === $provider
                && $item->type() === $type
                && $item->isPrimary()
            ) {
                return $item;
            }
        }

        return null;
    }

    public function save(ContactIdentity $identity): void
    {
        $this->items[$identity->id()->toString()] = $identity;
    }

    /** @return list<ContactIdentity> */
    public function all(): array
    {
        return array_values($this->items);
    }
}

final class InMemoryConversationRepository implements ConversationRepository
{
    /** @var array<string, Conversation> */
    private array $items = [];

    public function findByManagerAccountAndContact(
        ManagerAccountId $managerAccountId,
        ContactId $contactId,
    ): ?Conversation {
        foreach ($this->items as $item) {
            if (
                $item->managerAccountId()->toString() === $managerAccountId->toString()
                && $item->contactId()->toString() === $contactId->toString()
            ) {
                return $item;
            }
        }

        return null;
    }

    public function findById(ConversationId $id): ?Conversation
    {
        return $this->items[$id->toString()] ?? null;
    }

    /**
     * @return list<Conversation>
     */
    public function findByContact(ContactId $contactId): array
    {
        $result = [];
        foreach ($this->items as $item) {
            if ($item->contactId()->toString() !== $contactId->toString()) {
                continue;
            }
            $result[] = $item;
        }

        usort(
            $result,
            static fn (Conversation $a, Conversation $b): int => $b->lastActivityAt() <=> $a->lastActivityAt(),
        );

        return $result;
    }

    public function save(Conversation $conversation): void
    {
        $this->items[$conversation->id()->toString()] = $conversation;
    }

    /** @return list<Conversation> */
    public function all(): array
    {
        return array_values($this->items);
    }
}

final class InMemoryMessageRepository implements MessageRepository
{
    /** @var array<string, Message> */
    private array $items = [];

    public function findById(MessageId $id): ?Message
    {
        return $this->items[$id->toString()] ?? null;
    }

    public function save(Message $message): void
    {
        $this->items[$message->id()->toString()] = $message;
    }

    /** @return list<Message> */
    public function all(): array
    {
        return array_values($this->items);
    }
}

final class InMemoryAttachmentRepository implements AttachmentRepository
{
    /** @var array<string, Attachment> */
    private array $items = [];

    public function save(Attachment $attachment): void
    {
        $this->items[$attachment->id()->toString()] = $attachment;
    }

    /** @return list<Attachment> */
    public function all(): array
    {
        return array_values($this->items);
    }
}

final class InMemoryCrmThreadRepository implements CrmThreadRepository
{
    /** @var array<string, CRMThread> */
    private array $items = [];

    public function findByConversationAndProvider(ConversationId $conversationId, CrmProvider $provider): ?CRMThread
    {
        foreach ($this->items as $item) {
            if ($item->conversationId()->toString() === $conversationId->toString() && $item->crmProvider() === $provider) {
                return $item;
            }
        }

        return null;
    }

    public function findByProviderAndExternalThreadId(CrmProvider $provider, string $externalThreadId): ?CRMThread
    {
        foreach ($this->items as $item) {
            if ($item->crmProvider() === $provider && $item->externalThreadId() === $externalThreadId) {
                return $item;
            }
        }

        return null;
    }

    public function save(CRMThread $crmThread): void
    {
        $this->items[$crmThread->id()->toString()] = $crmThread;
    }

    /** @return list<CRMThread> */
    public function all(): array
    {
        return array_values($this->items);
    }
}

final class InMemoryDeliveryRepository implements DeliveryRepository
{
    /** @var array<string, Delivery> */
    private array $items = [];

    public function save(Delivery $delivery): void
    {
        $this->items[$delivery->id()->toString()] = $delivery;
    }

    /** @return list<Delivery> */
    public function all(): array
    {
        return array_values($this->items);
    }
}

final class InMemoryMessageReferenceRepository implements MessageReferenceRepository
{
    /** @var array<string, string> */
    private array $channelMappings = [];

    /** @var array<string, string> */
    private array $crmMappings = [];

    public function hasChannelMessage(ChannelProvider $provider, string $scopeId, string $externalMessageId): bool
    {
        return isset($this->channelMappings[$this->channelKey($provider->value, $scopeId, $externalMessageId)]);
    }

    public function saveChannelMessage(
        ChannelProvider $provider,
        string $scopeId,
        string $externalMessageId,
        MessageId $messageId,
    ): void {
        $this->channelMappings[$this->channelKey($provider->value, $scopeId, $externalMessageId)] = $messageId->toString();
    }

    public function hasCrmMessage(CrmProvider $provider, string $scopeId, string $externalMessageId): bool
    {
        return isset($this->crmMappings[$this->crmKey($provider->value, $scopeId, $externalMessageId)]);
    }

    public function saveCrmMessage(
        CrmProvider $provider,
        string $scopeId,
        string $externalMessageId,
        MessageId $messageId,
    ): void {
        $this->crmMappings[$this->crmKey($provider->value, $scopeId, $externalMessageId)] = $messageId->toString();
    }

    private function channelKey(string $provider, string $scopeId, string $externalMessageId): string
    {
        return sprintf('%s:%s:%s', $provider, $scopeId, $externalMessageId);
    }

    private function crmKey(string $provider, string $scopeId, string $externalMessageId): string
    {
        return sprintf('%s:%s:%s', $provider, $scopeId, $externalMessageId);
    }
}

final class InMemoryProcessedEventRepository implements ProcessedEventRepository
{
    /** @var array<string, true> */
    private array $items = [];

    public function has(string $source, string $eventId): bool
    {
        return isset($this->items[$this->key($source, $eventId)]);
    }

    public function add(string $source, string $eventId): void
    {
        $this->items[$this->key($source, $eventId)] = true;
    }

    private function key(string $source, string $eventId): string
    {
        return sprintf('%s:%s', $source, $eventId);
    }
}

final class SpyCrmConnector implements CrmConnector
{
    public int $ensureThreadCalls = 0;
    public int $sendMessageCalls = 0;

    /** @var list<OpenCrmThreadRequest> */
    public array $openedThreadRequests = [];

    /** @var list<SendCrmMessageRequest> */
    public array $sentMessageRequests = [];

    public function __construct(
        private readonly string $threadId = 'bitrix-thread-1',
        private readonly string $messagePrefix = 'bitrix-message',
    ) {
    }

    public function ensureThread(OpenCrmThreadRequest $request): OpenCrmThreadResult
    {
        $this->ensureThreadCalls++;
        $this->openedThreadRequests[] = $request;

        return new OpenCrmThreadResult($this->threadId);
    }

    public function sendMessage(SendCrmMessageRequest $request): SendCrmMessageResult
    {
        $this->sendMessageCalls++;
        $this->sentMessageRequests[] = $request;

        return new SendCrmMessageResult(sprintf('%s-%d', $this->messagePrefix, $this->sendMessageCalls));
    }
}

final class StaticCrmConnectorRegistry implements CrmConnectorRegistry
{
    /** @param array<string, CrmConnector> $connectors */
    public function __construct(private readonly array $connectors)
    {
    }

    public function for(CrmProvider $provider): CrmConnector
    {
        if (!isset($this->connectors[$provider->value])) {
            throw new RuntimeException(sprintf('CRM connector for "%s" is not configured.', $provider->value));
        }

        return $this->connectors[$provider->value];
    }
}

final class SpyChannelConnector implements ChannelConnector
{
    public int $sendMessageCalls = 0;

    /** @var list<SendChannelMessageRequest> */
    public array $sentMessageRequests = [];

    public function __construct(private readonly string $messagePrefix = 'telegram-message')
    {
    }

    public function sendMessage(SendChannelMessageRequest $request): SendChannelMessageResult
    {
        $this->sendMessageCalls++;
        $this->sentMessageRequests[] = $request;

        return new SendChannelMessageResult(sprintf('%s-%d', $this->messagePrefix, $this->sendMessageCalls));
    }
}

final class StaticChannelConnectorRegistry implements ChannelConnectorRegistry
{
    /** @param array<string, ChannelConnector> $connectors */
    public function __construct(private readonly array $connectors)
    {
    }

    public function for(ChannelProvider $provider): ChannelConnector
    {
        if (!isset($this->connectors[$provider->value])) {
            throw new RuntimeException(sprintf('Channel connector for "%s" is not configured.', $provider->value));
        }

        return $this->connectors[$provider->value];
    }
}

final class InMemoryExternalOperationLogger implements ExternalOperationLogger
{
    /** @var list<ExternalOperationLogEntry> */
    private array $entries = [];

    public function log(ExternalOperationLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<ExternalOperationLogEntry> */
    public function entries(): array
    {
        return $this->entries;
    }
}
