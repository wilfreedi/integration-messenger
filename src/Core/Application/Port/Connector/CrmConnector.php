<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Connector;

interface CrmConnector
{
    public function ensureThread(OpenCrmThreadRequest $request): OpenCrmThreadResult;

    public function sendMessage(SendCrmMessageRequest $request): SendCrmMessageResult;
}

