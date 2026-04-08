<?php

declare(strict_types=1);

namespace ChatSync\App\Http\Controller;

use ChatSync\App\Http\Validator\BitrixAppInstallValidator;
use ChatSync\App\Integration\Bitrix\RegisterBitrixPortalInstallHandler;

final readonly class BitrixAppInstallController
{
    public function __construct(
        private BitrixAppInstallValidator $validator,
        private RegisterBitrixPortalInstallHandler $handler,
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
            'status' => 'connected',
            'portal_domain' => $result->portalDomain,
            'portal_id' => $result->portalId,
            'install_id' => $result->installId,
            'expires_at' => $result->expiresAt,
        ];
    }
}

