<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

final readonly class BitrixOAuthToken
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public int $expiresInSeconds,
        public ?string $scope,
    ) {
    }
}

