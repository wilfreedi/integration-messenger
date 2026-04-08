<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

use ChatSync\Core\Application\Port\Connector\CrmConnector;
use ChatSync\Core\Application\Port\Connector\OpenCrmThreadRequest;
use ChatSync\Core\Application\Port\Connector\OpenCrmThreadResult;
use ChatSync\Core\Application\Port\Connector\SendCrmMessageRequest;
use ChatSync\Core\Application\Port\Connector\SendCrmMessageResult;

final class StubBitrixOpenLinesConnector implements CrmConnector
{
    public function ensureThread(OpenCrmThreadRequest $request): OpenCrmThreadResult
    {
        return new OpenCrmThreadResult(sprintf('bitrix-thread-%s', $request->conversationId));
    }

    public function sendMessage(SendCrmMessageRequest $request): SendCrmMessageResult
    {
        return new SendCrmMessageResult(sprintf('bitrix-message-%s', bin2hex(random_bytes(6))));
    }
}

