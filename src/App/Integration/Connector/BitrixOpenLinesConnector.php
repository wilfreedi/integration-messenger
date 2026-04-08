<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

use ChatSync\App\Integration\Bitrix\BitrixRoutingResolver;
use ChatSync\Core\Application\Port\Connector\CrmConnector;
use ChatSync\Core\Application\Port\Connector\OpenCrmThreadRequest;
use ChatSync\Core\Application\Port\Connector\OpenCrmThreadResult;
use ChatSync\Core\Application\Port\Connector\SendCrmMessageRequest;
use ChatSync\Core\Application\Port\Connector\SendCrmMessageResult;
use DateTimeImmutable;
use RuntimeException;

final readonly class BitrixOpenLinesConnector implements CrmConnector
{
    public function __construct(
        private BitrixRestClient $restClient,
        private BitrixRoutingResolver $routingResolver,
    ) {
    }

    public function ensureThread(OpenCrmThreadRequest $request): OpenCrmThreadResult
    {
        // Open Lines thread is materialized by first message send.
        return new OpenCrmThreadResult($request->conversationId);
    }

    public function sendMessage(SendCrmMessageRequest $request): SendCrmMessageResult
    {
        $externalUserId = $request->externalContactUserId !== null && $request->externalContactUserId !== ''
            ? $request->externalContactUserId
            : $request->externalThreadId;

        $externalMessageId = $this->apiFor($request)->sendMessage(
            externalThreadId: $request->externalThreadId,
            externalUserId: $externalUserId,
            contactDisplayName: $request->contactDisplayName,
            body: $request->body,
            occurredAt: $request->occurredAt,
            sourceMessageId: 'channel-' . $request->correlationId,
        );

        return new SendCrmMessageResult($externalMessageId);
    }

    private function apiFor(SendCrmMessageRequest $request): BitrixOpenLinesApi
    {
        $route = $this->routingResolver->resolveForManagerAccount(
            $request->channelProvider->value,
            $request->managerAccountExternalId,
        );
        if ($route === null) {
            throw new RuntimeException(sprintf(
                'Bitrix binding is not configured for manager account "%s".',
                $request->managerAccountExternalId,
            ));
        }

        if ($route->restBaseUrl === '' || $route->connectorId === '' || $route->lineId === '') {
            throw new RuntimeException(sprintf(
                'Bitrix routing context is incomplete for manager account "%s".',
                $request->managerAccountExternalId,
            ));
        }

        if ($route->accessToken !== null && $route->expiresAt <= new DateTimeImmutable()) {
            throw new RuntimeException(sprintf(
                'Bitrix access token is expired for manager account "%s".',
                $request->managerAccountExternalId,
            ));
        }

        return new BitrixOpenLinesApi(
            $route->restBaseUrl,
            $route->connectorId,
            $route->lineId,
            $this->restClient,
            $route->accessToken,
        );
    }
}
