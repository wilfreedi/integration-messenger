<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Http\Validator\BitrixOpenLinesWebhookValidator;
use ChatSync\App\Integration\Bitrix\BitrixRoutingResolver;
use ChatSync\App\Integration\Connector\BitrixOpenLinesApi;
use ChatSync\App\Integration\Connector\BitrixRestClient;
use ChatSync\App\Query\MessageMappingLookup;
use ChatSync\Core\Application\Handler\SyncOutboundCrmMessageHandler;
use ChatSync\Core\Domain\Enum\ExternalSystemType;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BitrixOpenLinesWebhookController
{
    public function __construct(
        private BitrixOpenLinesWebhookValidator $validator,
        private SyncOutboundCrmMessageHandler $handler,
        private MessageMappingLookup $messageLookup,
        private BitrixRoutingResolver $routingResolver,
        private BitrixRestClient $bitrixRestClient,
        private string $webhookToken = '',
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload, string $token = ''): array
    {
        if ($this->webhookToken !== '' && !hash_equals($this->webhookToken, $token)) {
            throw new InvalidArgumentException('Invalid Bitrix webhook token.');
        }

        $messages = $this->validator->validate($payload);
        $results = [];
        $deliveryAckSent = 0;

        foreach ($messages as $message) {
            $result = ($this->handler)($message->command);

            $ackSent = false;
            if (
                $message->imMessageId !== null
                && $message->imChatId !== null
            ) {
                $channelExternalMessageId = $result->messageId !== null
                    ? $this->messageLookup->findChannelExternalMessageIdByInternalMessageId(
                        $result->messageId,
                        $message->command->channelProvider->value,
                    )
                    : $this->messageLookup->findChannelExternalMessageIdByCrmExternalMessage(
                        $message->command->crmProvider->value,
                        $message->command->externalThreadId,
                        $message->command->externalMessageId,
                        $message->command->channelProvider->value,
                    );

                if ($channelExternalMessageId !== null) {
                    $ackApi = $this->resolveDeliveryAckApi(
                        $result->messageId,
                        $message->command->crmProvider->value,
                        $message->command->externalThreadId,
                        $message->command->externalMessageId,
                        $message->command->channelProvider->value,
                    );

                    if ($ackApi !== null) {
                        $ackApi->sendDeliveryStatus(
                            $message->imMessageId,
                            $message->imChatId,
                            [$channelExternalMessageId]
                        );
                        $deliveryAckSent++;
                        $ackSent = true;
                    }
                }
            }

            $results[] = [
                'event_id' => $message->command->eventId,
                'status' => $result->processed ? 'processed' : 'skipped',
                'reason' => $result->reason,
                'message_id' => $result->messageId,
                'delivery_ack_sent' => $ackSent,
            ];
        }

        return [
            'status' => 'accepted',
            'events' => $results,
            'delivery_ack_total' => $deliveryAckSent,
            'system' => ExternalSystemType::CRM->value,
        ];
    }

    private function resolveDeliveryAckApi(
        ?string $internalMessageId,
        string $crmProvider,
        string $externalThreadId,
        string $crmExternalMessageId,
        string $channelProvider,
    ): ?BitrixOpenLinesApi {
        $managerAccountExternalId = $internalMessageId !== null
            ? $this->messageLookup->findManagerAccountExternalIdByInternalMessageId($internalMessageId)
            : $this->messageLookup->findManagerAccountExternalIdByCrmExternalMessage(
                $crmProvider,
                $externalThreadId,
                $crmExternalMessageId,
            );
        if ($managerAccountExternalId === null) {
            return null;
        }

        $route = $this->routingResolver->resolveForManagerAccount($channelProvider, $managerAccountExternalId);
        if (
            $route === null
            || $route->restBaseUrl === ''
            || $route->connectorId === ''
            || $route->lineId === ''
            || ($route->accessToken !== null && $route->expiresAt <= new DateTimeImmutable())
        ) {
            return null;
        }

        return new BitrixOpenLinesApi(
            $route->restBaseUrl,
            $route->connectorId,
            $route->lineId,
            $this->bitrixRestClient,
            $route->accessToken,
        );
    }
}
