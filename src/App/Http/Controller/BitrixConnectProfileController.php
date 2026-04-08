<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Http\Validator\BitrixAppInstallValidator;
use ChatSync\App\Http\Validator\ManagerBitrixBindingValidator;
use ChatSync\App\Integration\Bitrix\RegisterBitrixPortalInstallHandler;
use ChatSync\App\Integration\Bitrix\UpsertManagerBitrixBindingHandler;

final readonly class BitrixConnectProfileController
{
    public function __construct(
        private BitrixAppInstallValidator $installValidator,
        private RegisterBitrixPortalInstallHandler $installHandler,
        private ManagerBitrixBindingValidator $bindingValidator,
        private UpsertManagerBitrixBindingHandler $bindingHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $installCommand = $this->installValidator->validate($payload);
        $installResult = ($this->installHandler)($installCommand);

        $bindingPayload = $payload;
        $bindingPayload['portal_domain'] = $installCommand->portalDomain;
        $bindingResult = ($this->bindingHandler)($this->bindingValidator->validate($bindingPayload));

        return [
            'status' => 'connected',
            'portal' => [
                'portal_domain' => $installResult->portalDomain,
                'portal_id' => $installResult->portalId,
                'install_id' => $installResult->installId,
                'expires_at' => $installResult->expiresAt,
            ],
            'binding' => [
                'binding_id' => $bindingResult->bindingId,
                'manager_account_external_id' => $bindingResult->managerAccountExternalId,
                'portal_domain' => $bindingResult->portalDomain,
                'line_id' => $bindingResult->lineId,
                'is_enabled' => $bindingResult->isEnabled,
            ],
        ];
    }
}
