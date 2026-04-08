<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Http\Validator\ManagerBitrixBindingValidator;
use ChatSync\App\Integration\Bitrix\UpsertManagerBitrixBindingHandler;

final readonly class ManagerBitrixBindingController
{
    public function __construct(
        private ManagerBitrixBindingValidator $validator,
        private UpsertManagerBitrixBindingHandler $handler,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $result = ($this->handler)($this->validator->validate($payload));

        return [
            'status' => 'saved',
            'binding_id' => $result->bindingId,
            'manager_account_external_id' => $result->managerAccountExternalId,
            'portal_domain' => $result->portalDomain,
            'line_id' => $result->lineId,
            'is_enabled' => $result->isEnabled,
        ];
    }
}
