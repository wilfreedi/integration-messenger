<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Query\RuntimeStateInspector;

final readonly class DebugStateController
{
    public function __construct(private RuntimeStateInspector $inspector)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return $this->inspector->snapshot();
    }
}

