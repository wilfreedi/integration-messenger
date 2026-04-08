<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Query\BitrixIntegrationQuery;

final readonly class ManagerBitrixBindingsController
{
    public function __construct(private BitrixIntegrationQuery $query)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $items = $this->query->bindings();

        return [
            'items' => $items,
            'count' => count($items),
        ];
    }
}
