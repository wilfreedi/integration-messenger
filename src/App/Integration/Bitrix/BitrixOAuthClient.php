<?php

declare(strict_types=1);

namespace ChatSync\App\Integration\Bitrix;

interface BitrixOAuthClient
{
    public function refreshToken(
        string $tokenEndpoint,
        string $clientId,
        string $clientSecret,
        string $refreshToken
    ): BitrixOAuthToken;
}

