<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Handler;

use ChatSync\Core\Application\Command\SyncInboundChannelMessageCommand;
use ChatSync\Core\Application\Exception\ManagerAccountNotFound;
use ChatSync\Core\Application\Port\Connector\CrmConnectorRegistry;
use ChatSync\Core\Application\Port\Connector\OpenCrmThreadRequest;
use ChatSync\Core\Application\Port\Connector\SendCrmMessageRequest;
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
use ChatSync\Core\Application\Result\SyncResult;
use ChatSync\Core\Domain\Enum\ContactIdentityType;
use ChatSync\Core\Domain\Enum\DeliveryStatus;
use ChatSync\Core\Domain\Enum\ExternalSystemType;
use ChatSync\Core\Domain\Enum\IntegrationDirection;
use ChatSync\Core\Domain\Model\Attachment;
use ChatSync\Core\Domain\Model\Contact;
use ChatSync\Core\Domain\Model\ContactIdentity;
use ChatSync\Core\Domain\Model\Conversation;
use ChatSync\Core\Domain\Model\CRMThread;
use ChatSync\Core\Domain\Model\Delivery;
use ChatSync\Core\Domain\Model\Message;
use ChatSync\Core\Domain\ValueObject\AttachmentId;
use ChatSync\Core\Domain\ValueObject\ContactId;
use ChatSync\Core\Domain\ValueObject\ContactIdentityId;
use ChatSync\Core\Domain\ValueObject\ConversationId;
use ChatSync\Core\Domain\ValueObject\CrmThreadId;
use ChatSync\Core\Domain\ValueObject\DeliveryId;
use ChatSync\Core\Domain\ValueObject\MessageId;
use ChatSync\Shared\Domain\Clock;
use ChatSync\Shared\Domain\IdGenerator;
use Throwable;

final class SyncInboundChannelMessageHandler
{
    public function __construct(
        private readonly ManagerAccountRepository $managerAccounts,
        private readonly ContactRepository $contacts,
        private readonly ContactIdentityRepository $contactIdentities,
        private readonly ConversationRepository $conversations,
        private readonly MessageRepository $messages,
        private readonly AttachmentRepository $attachments,
        private readonly CrmThreadRepository $crmThreads,
        private readonly DeliveryRepository $deliveries,
        private readonly MessageReferenceRepository $messageReferences,
        private readonly ProcessedEventRepository $processedEvents,
        private readonly CrmConnectorRegistry $crmConnectors,
        private readonly ExternalOperationLogger $logger,
        private readonly IdGenerator $idGenerator,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(SyncInboundChannelMessageCommand $command): SyncResult
    {
        $eventSource = $this->eventSource($command->channelProvider->value);
        $this->logger->log(new ExternalOperationLogEntry(
            $command->channelProvider->value,
            IntegrationDirection::INBOUND,
            $command->eventId,
            $command->externalMessageId,
            'webhook_received',
            [
                'manager_account_external_id' => $command->managerAccountExternalId,
                'contact_external_chat_id' => $command->contactExternalChatId,
                'crm_provider' => $command->crmProvider->value,
            ],
        ));

        if ($this->processedEvents->has($eventSource, $command->eventId)) {
            $this->logger->log(new ExternalOperationLogEntry(
                $command->channelProvider->value,
                IntegrationDirection::INBOUND,
                $command->eventId,
                $command->externalMessageId,
                'webhook_duplicate',
                [
                    'manager_account_external_id' => $command->managerAccountExternalId,
                ],
            ));
            return SyncResult::duplicate('duplicate_event');
        }

        $managerAccount = $this->managerAccounts->findByProviderAndExternalAccountId(
            $command->channelProvider,
            $command->managerAccountExternalId,
        );

        if ($managerAccount === null) {
            $this->logger->log(new ExternalOperationLogEntry(
                $command->channelProvider->value,
                IntegrationDirection::INBOUND,
                $command->eventId,
                $command->externalMessageId,
                'manager_account_not_found',
                [
                    'manager_account_external_id' => $command->managerAccountExternalId,
                ],
            ));
            throw new ManagerAccountNotFound(sprintf(
                'Manager account not found for provider "%s" and external id "%s".',
                $command->channelProvider->value,
                $command->managerAccountExternalId,
            ));
        }

        $scopeId = $managerAccount->id()->toString();

        if ($this->messageReferences->hasChannelMessage($command->channelProvider, $scopeId, $command->externalMessageId)) {
            $this->processedEvents->add($eventSource, $command->eventId);

            return SyncResult::duplicate('duplicate_external_message');
        }

        $contact = $this->resolveContact($command);
        $conversation = $this->resolveConversation($managerAccount->id(), $contact->id(), $command->occurredAt);

        $message = Message::inbound(
            MessageId::generate($this->idGenerator),
            $conversation->id(),
            $command->body,
            $command->occurredAt,
            $this->clock->now(),
        );

        $this->messages->save($message);
        $this->storeAttachments($message->id(), $command);

        $correlationId = $this->idGenerator->next();
        $crmThread = $this->ensureCrmThread($conversation, $managerAccount->externalAccountId(), $contact->displayName(), $command, $correlationId);

        $crmConnector = $this->crmConnectors->for($command->crmProvider);
        $this->logger->log(new ExternalOperationLogEntry(
            $command->crmProvider->value,
            IntegrationDirection::OUTBOUND,
            $correlationId,
            $crmThread->externalThreadId(),
            'send_message_attempt',
            [
                'manager_account_external_id' => $managerAccount->externalAccountId(),
                'contact_external_chat_id' => $command->contactExternalChatId,
                'contact_external_user_id' => $command->contactExternalUserId,
                'crm_external_thread_id' => $crmThread->externalThreadId(),
            ],
        ));

        try {
            $crmResult = $crmConnector->sendMessage(new SendCrmMessageRequest(
                $crmThread->externalThreadId(),
                $command->channelProvider,
                $managerAccount->externalAccountId(),
                $contact->displayName(),
                $command->contactExternalChatId,
                $command->contactExternalUserId,
                $command->body,
                $command->occurredAt,
                $correlationId,
                $command->attachments,
            ));
        } catch (Throwable $exception) {
            $this->logger->log(new ExternalOperationLogEntry(
                $command->crmProvider->value,
                IntegrationDirection::OUTBOUND,
                $correlationId,
                $crmThread->externalThreadId(),
                'send_message_failed',
                [
                    'manager_account_external_id' => $managerAccount->externalAccountId(),
                    'contact_external_chat_id' => $command->contactExternalChatId,
                    'error' => $exception->getMessage(),
                ],
            ));
            throw $exception;
        }

        $this->deliveries->save(new Delivery(
            DeliveryId::generate($this->idGenerator),
            $message->id(),
            ExternalSystemType::CRM,
            $command->crmProvider->value,
            IntegrationDirection::OUTBOUND,
            $crmResult->externalMessageId,
            $correlationId,
            DeliveryStatus::SENT,
            $this->clock->now(),
        ));

        $this->logger->log(new ExternalOperationLogEntry(
            $command->crmProvider->value,
            IntegrationDirection::OUTBOUND,
            $correlationId,
            $crmResult->externalMessageId,
            'send_message',
            [
                'conversation_id' => $conversation->id()->toString(),
                'message_id' => $message->id()->toString(),
                'crm_external_message_id' => $crmResult->externalMessageId,
            ],
        ));

        $this->messageReferences->saveChannelMessage(
            $command->channelProvider,
            $scopeId,
            $command->externalMessageId,
            $message->id(),
        );
        $this->messageReferences->saveCrmMessage(
            $command->crmProvider,
            $crmThread->id()->toString(),
            $crmResult->externalMessageId,
            $message->id(),
        );
        $this->processedEvents->add($eventSource, $command->eventId);

        return SyncResult::processed($message->id()->toString());
    }

    private function resolveContact(SyncInboundChannelMessageCommand $command): Contact
    {
        $provider = $command->channelProvider->value;
        $existingIdentity = $this->contactIdentities->findByProviderTypeAndValue(
            $provider,
            ContactIdentityType::CHANNEL_CHAT_ID,
            $command->contactExternalChatId,
        );

        if ($existingIdentity !== null) {
            $contact = $this->contacts->findById($existingIdentity->contactId());

            if ($contact !== null) {
                return $contact;
            }
        }

        $contact = new Contact(
            ContactId::generate($this->idGenerator),
            $command->contactDisplayName,
            null,
            $this->clock->now(),
        );
        $this->contacts->save($contact);

        $this->contactIdentities->save(new ContactIdentity(
            ContactIdentityId::generate($this->idGenerator),
            $contact->id(),
            $provider,
            ContactIdentityType::CHANNEL_CHAT_ID,
            $command->contactExternalChatId,
            true,
            $this->clock->now(),
        ));

        if ($command->contactExternalUserId !== null) {
            $this->contactIdentities->save(new ContactIdentity(
                ContactIdentityId::generate($this->idGenerator),
                $contact->id(),
                $provider,
                ContactIdentityType::CHANNEL_USER_ID,
                $command->contactExternalUserId,
                false,
                $this->clock->now(),
            ));
        }

        return $contact;
    }

    private function resolveConversation(
        \ChatSync\Core\Domain\ValueObject\ManagerAccountId $managerAccountId,
        ContactId $contactId,
        \DateTimeImmutable $occurredAt,
    ): Conversation {
        $existingConversation = $this->conversations->findByManagerAccountAndContact($managerAccountId, $contactId);

        if ($existingConversation !== null) {
            $conversation = $existingConversation->recordActivity($occurredAt);
            $this->conversations->save($conversation);

            return $conversation;
        }

        $conversation = Conversation::start(
            ConversationId::generate($this->idGenerator),
            $managerAccountId,
            $contactId,
            $occurredAt,
        );
        $this->conversations->save($conversation);

        return $conversation;
    }

    private function storeAttachments(MessageId $messageId, SyncInboundChannelMessageCommand $command): void
    {
        foreach ($command->attachments as $attachmentData) {
            $this->attachments->save(new Attachment(
                AttachmentId::generate($this->idGenerator),
                $messageId,
                $attachmentData->type,
                $attachmentData->externalFileId,
                $attachmentData->fileName,
                $attachmentData->mimeType,
                $this->clock->now(),
            ));
        }
    }

    private function ensureCrmThread(
        Conversation $conversation,
        string $managerAccountExternalId,
        string $contactDisplayName,
        SyncInboundChannelMessageCommand $command,
        string $correlationId,
    ): CRMThread {
        $existingThread = $this->crmThreads->findByConversationAndProvider($conversation->id(), $command->crmProvider);

        if ($existingThread !== null) {
            return $existingThread;
        }

        $crmConnector = $this->crmConnectors->for($command->crmProvider);
        $threadResult = $crmConnector->ensureThread(new OpenCrmThreadRequest(
            $conversation->id()->toString(),
            $managerAccountExternalId,
            $contactDisplayName,
            $correlationId,
        ));

        $crmThread = new CRMThread(
            CrmThreadId::generate($this->idGenerator),
            $conversation->id(),
            $command->crmProvider,
            $threadResult->externalThreadId,
            $this->clock->now(),
        );
        $this->crmThreads->save($crmThread);

        $this->logger->log(new ExternalOperationLogEntry(
            $command->crmProvider->value,
            IntegrationDirection::OUTBOUND,
            $correlationId,
            $threadResult->externalThreadId,
            'ensure_thread',
            [
                'conversation_id' => $conversation->id()->toString(),
            ],
        ));

        return $crmThread;
    }

    private function eventSource(string $provider): string
    {
        return sprintf('channel:%s', $provider);
    }
}
