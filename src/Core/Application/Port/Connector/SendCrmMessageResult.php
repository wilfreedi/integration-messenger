<?php

declare(strict_types=1);

namespace ChatSync\Core\Application\Port\Connector;

final readonly class SendCrmMessageResult
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(public string $externalMessageId, public array $meta = [])
    {
    }
}
