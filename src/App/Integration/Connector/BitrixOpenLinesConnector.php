<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

use ChatSync\App\Integration\Bitrix\BitrixRoutingResolver;
use ChatSync\App\Integration\Bitrix\BitrixTokenManager;
use ChatSync\Core\Application\Port\Connector\CrmConnector;
use ChatSync\Core\Application\Port\Connector\OpenCrmThreadRequest;
use ChatSync\Core\Application\Port\Connector\OpenCrmThreadResult;
use ChatSync\Core\Application\Port\Connector\SendCrmMessageRequest;
use ChatSync\Core\Application\Port\Connector\SendCrmMessageResult;
use RuntimeException;

final readonly class BitrixOpenLinesConnector implements CrmConnector
{
    public function __construct(
        private BitrixRestClient $restClient,
        private BitrixRoutingResolver $routingResolver,
        private BitrixTokenManager $tokenManager,
        private BitrixOpenLinesConnectorLifecycle $lifecycle,
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
            : (($request->externalContactChatId !== null && $request->externalContactChatId !== '')
                ? $request->externalContactChatId
                : $request->externalThreadId);
        $externalChatId = $request->externalContactChatId !== null && $request->externalContactChatId !== ''
            ? $request->externalContactChatId
            : (($request->externalContactUserId !== null && $request->externalContactUserId !== '')
                ? $request->externalContactUserId
                : $request->externalThreadId);

        $externalMessageId = $this->apiFor($request)->sendMessage(
            externalThreadId: $externalChatId,
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

        $route = $this->tokenManager->ensureValidRoute($route, $request->managerAccountExternalId);
        $this->lifecycle->ensure(
            $route->restBaseUrl,
            $route->connectorId,
            $route->lineId,
            $route->accessToken,
        );

        return new BitrixOpenLinesApi(
            $route->restBaseUrl,
            $route->connectorId,
            $route->lineId,
            $this->restClient,
            $route->accessToken,
        );
    }
}
