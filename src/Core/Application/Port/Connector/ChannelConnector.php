<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Connector;

interface ChannelConnector
{
    public function sendMessage(SendChannelMessageRequest $request): SendChannelMessageResult;
}

