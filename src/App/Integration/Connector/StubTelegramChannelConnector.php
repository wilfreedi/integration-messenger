<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Connector;

use ChatSync\Core\Application\Port\Connector\ChannelConnector;
use ChatSync\Core\Application\Port\Connector\SendChannelMessageRequest;
use ChatSync\Core\Application\Port\Connector\SendChannelMessageResult;

final class StubTelegramChannelConnector implements ChannelConnector
{
    public function sendMessage(SendChannelMessageRequest $request): SendChannelMessageResult
    {
        return new SendChannelMessageResult(sprintf('telegram-message-%s', bin2hex(random_bytes(6))));
    }
}

