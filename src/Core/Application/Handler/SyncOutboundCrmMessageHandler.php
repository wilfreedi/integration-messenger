<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Handler;

use ChatSync\Core\Application\Command\SyncOutboundCrmMessageCommand;
use ChatSync\Core\Application\Exception\ContactIdentityNotFound;
use ChatSync\Core\Application\Exception\CrmThreadNotFound;
use ChatSync\Core\Application\Exception\ManagerAccountNotFound;
use ChatSync\Core\Application\Port\Connector\ChannelConnectorRegistry;
use ChatSync\Core\Application\Port\Connector\SendChannelMessageRequest;
use ChatSync\Core\Application\Port\Logging\ExternalOperationLogEntry;
use ChatSync\Core\Application\Port\Logging\ExternalOperationLogger;
use ChatSync\Core\Application\Port\Persistence\AttachmentRepository;
use ChatSync\Core\Application\Port\Persistence\ContactIdentityRepository;
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
use ChatSync\Core\Domain\Model\Delivery;
use ChatSync\Core\Domain\Model\Message;
use ChatSync\Core\Domain\ValueObject\AttachmentId;
use ChatSync\Core\Domain\ValueObject\DeliveryId;
use ChatSync\Core\Domain\ValueObject\MessageId;
use ChatSync\Shared\Domain\Clock;
use ChatSync\Shared\Domain\IdGenerator;

final class SyncOutboundCrmMessageHandler
{
    public function __construct(
        private readonly CrmThreadRepository $crmThreads,
        private readonly ConversationRepository $conversations,
        private readonly ManagerAccountRepository $managerAccounts,
        private readonly ContactIdentityRepository $contactIdentities,
        private readonly MessageRepository $messages,
        private readonly AttachmentRepository $attachments,
        private readonly DeliveryRepository $deliveries,
        private readonly MessageReferenceRepository $messageReferences,
        private readonly ProcessedEventRepository $processedEvents,
        private readonly ChannelConnectorRegistry $channelConnectors,
        private readonly ExternalOperationLogger $logger,
        private readonly IdGenerator $idGenerator,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(SyncOutboundCrmMessageCommand $command): SyncResult
    {
        $eventSource = $this->eventSource($command->crmProvider->value);

        if ($this->processedEvents->has($eventSource, $command->eventId)) {
            return SyncResult::duplicate('duplicate_event');
        }

        $crmThread = $this->crmThreads->findByProviderAndExternalThreadId($command->crmProvider, $command->externalThreadId);
        $threadResolvedBy = 'external_thread_id';

        if ($crmThread === null) {
            $crmThread = $this->resolveCrmThreadByChannelChatId($command);
            if ($crmThread !== null) {
                $threadResolvedBy = 'channel_chat_id_fallback';
            }
        }

        if ($crmThread === null) {
            throw new CrmThreadNotFound(sprintf(
                'CRM thread not found for provider "%s" and external thread "%s".',
                $command->crmProvider->value,
                $command->externalThreadId,
            ));
        }

        if ($this->messageReferences->hasCrmMessage(
            $command->crmProvider,
            $crmThread->id()->toString(),
            $command->externalMessageId,
        )) {
            $this->processedEvents->add($eventSource, $command->eventId);

            return SyncResult::duplicate('duplicate_external_message');
        }

        $conversation = $this->conversations->findById($crmThread->conversationId());

        if ($conversation === null) {
            throw new CrmThreadNotFound('Conversation not found for CRM thread.');
        }

        $managerAccount = $this->managerAccounts->findById($conversation->managerAccountId());

        if ($managerAccount === null) {
            throw new ManagerAccountNotFound('Manager account not found for conversation.');
        }

        $contactIdentity = $this->contactIdentities->findPrimaryForContactAndProvider(
            $conversation->contactId(),
            $command->channelProvider->value,
            ContactIdentityType::CHANNEL_CHAT_ID,
        );

        if ($contactIdentity === null) {
            throw new ContactIdentityNotFound('Primary channel chat identity not found for contact.');
        }

        $message = Message::outbound(
            MessageId::generate($this->idGenerator),
            $conversation->id(),
            $command->body,
            $command->occurredAt,
            $this->clock->now(),
        );
        $this->messages->save($message);
        $this->storeAttachments($message->id(), $command);

        $correlationId = $this->idGenerator->next();
        $channelConnector = $this->channelConnectors->for($command->channelProvider);
        $channelResult = $channelConnector->sendMessage(new SendChannelMessageRequest(
            $managerAccount->externalAccountId(),
            $contactIdentity->value(),
            $command->body,
            $command->occurredAt,
            $correlationId,
            $command->attachments,
        ));

        $this->deliveries->save(new Delivery(
            DeliveryId::generate($this->idGenerator),
            $message->id(),
            ExternalSystemType::CHANNEL,
            $command->channelProvider->value,
            IntegrationDirection::OUTBOUND,
            $channelResult->externalMessageId,
            $correlationId,
            DeliveryStatus::SENT,
            $this->clock->now(),
        ));

        $this->logger->log(new ExternalOperationLogEntry(
            $command->channelProvider->value,
            IntegrationDirection::OUTBOUND,
            $correlationId,
            $channelResult->externalMessageId,
            'send_message',
            [
                'thread_resolved_by' => $threadResolvedBy,
                'crm_external_thread_id' => $crmThread->externalThreadId(),
                'conversation_id' => $conversation->id()->toString(),
                'message_id' => $message->id()->toString(),
            ],
        ));

        $this->messageReferences->saveCrmMessage(
            $command->crmProvider,
            $crmThread->id()->toString(),
            $command->externalMessageId,
            $message->id(),
        );
        $this->messageReferences->saveChannelMessage(
            $command->channelProvider,
            $managerAccount->id()->toString(),
            $channelResult->externalMessageId,
            $message->id(),
        );
        $this->processedEvents->add($eventSource, $command->eventId);

        return SyncResult::processed($message->id()->toString());
    }

    private function storeAttachments(MessageId $messageId, SyncOutboundCrmMessageCommand $command): void
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

    private function eventSource(string $provider): string
    {
        return sprintf('crm:%s', $provider);
    }

    private function resolveCrmThreadByChannelChatId(SyncOutboundCrmMessageCommand $command): ?\ChatSync\Core\Domain\Model\CRMThread
    {
        $contactIdentity = $this->contactIdentities->findByProviderTypeAndValue(
            $command->channelProvider->value,
            ContactIdentityType::CHANNEL_CHAT_ID,
            $command->externalThreadId,
        );
        if ($contactIdentity === null) {
            return null;
        }

        $conversations = $this->conversations->findByContact($contactIdentity->contactId());
        foreach ($conversations as $conversation) {
            $thread = $this->crmThreads->findByConversationAndProvider($conversation->id(), $command->crmProvider);
            if ($thread !== null) {
                return $thread;
            }
        }

        return null;
    }
}
