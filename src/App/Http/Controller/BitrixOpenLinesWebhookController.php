<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Http\Validator\BitrixOpenLinesWebhookValidator;
use ChatSync\App\Integration\Bitrix\BitrixRoutingResolver;
use ChatSync\App\Integration\Bitrix\BitrixTokenManager;
use ChatSync\App\Integration\Connector\BitrixOpenLinesApi;
use ChatSync\App\Integration\Connector\BitrixRestClient;
use ChatSync\App\Query\MessageMappingLookup;
use ChatSync\Core\Application\Handler\SyncOutboundCrmMessageHandler;
use ChatSync\Core\Application\Port\Logging\ExternalOperationLogEntry;
use ChatSync\Core\Application\Port\Logging\ExternalOperationLogger;
use ChatSync\Core\Domain\Enum\ExternalSystemType;
use ChatSync\Core\Domain\Enum\IntegrationDirection;
use InvalidArgumentException;
use Throwable;

final readonly class BitrixOpenLinesWebhookController
{
    public function __construct(
        private BitrixOpenLinesWebhookValidator $validator,
        private SyncOutboundCrmMessageHandler $handler,
        private MessageMappingLookup $messageLookup,
        private BitrixRoutingResolver $routingResolver,
        private BitrixTokenManager $tokenManager,
        private BitrixRestClient $bitrixRestClient,
        private ExternalOperationLogger $logger,
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

        $webhookCorrelationId = sprintf('bitrix-webhook:%s', substr(sha1(json_encode($payload) ?: ''), 0, 20));
        $eventName = $this->firstString($payload, ['event', 'EVENT']) ?? 'unknown_event';
        $this->logger->log(new ExternalOperationLogEntry(
            'bitrix',
            IntegrationDirection::INBOUND,
            $webhookCorrelationId,
            $eventName,
            'webhook_received',
            [
                'has_data' => isset($payload['data']) || isset($payload['DATA']) ? 1 : 0,
            ],
        ));

        try {
            $messages = $this->validator->validate($payload);
        } catch (Throwable $exception) {
            $this->logger->log(new ExternalOperationLogEntry(
                'bitrix',
                IntegrationDirection::INBOUND,
                $webhookCorrelationId,
                $eventName,
                'webhook_validation_failed',
                [
                    'error' => $exception->getMessage(),
                ],
            ));
            throw $exception;
        }

        $results = [];
        $deliveryAckSent = 0;

        foreach ($messages as $message) {
            try {
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
            } catch (Throwable $exception) {
                $results[] = [
                    'event_id' => $message->command->eventId,
                    'status' => 'failed',
                    'reason' => 'processing_error',
                    'error' => $exception->getMessage(),
                    'delivery_ack_sent' => false,
                ];
            }
        }

        return [
            'status' => 'accepted',
            'events' => $results,
            'delivery_ack_total' => $deliveryAckSent,
            'system' => ExternalSystemType::CRM->value,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
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
        ) {
            return null;
        }

        try {
            $route = $this->tokenManager->ensureValidRoute($route, $managerAccountExternalId);
        } catch (\Throwable) {
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
