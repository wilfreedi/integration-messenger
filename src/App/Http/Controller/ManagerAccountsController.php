<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Query\ManagerAccountsQuery;

final readonly class ManagerAccountsController
{
    public function __construct(private ManagerAccountsQuery $query)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(?string $channelProvider = null): array
    {
        $items = $this->query->list($channelProvider);

        return [
            'items' => $items,
            'count' => count($items),
        ];
    }
}
