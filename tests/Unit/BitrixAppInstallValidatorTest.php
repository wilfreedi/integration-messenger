<?php

declare(strict_types=1);

namespace ChatSync\Tests\Unit;

use ChatSync\App\Http\Validator\BitrixAppInstallValidator;
use ChatSync\Tests\Support\Assertions;
use InvalidArgumentException;

final class BitrixAppInstallValidatorTest
{
    public static function run(): void
    {
        self::itParsesValidInstallPayload();
        self::itRejectsNonHttpsClientEndpoint();
        self::itRejectsMismatchedClientEndpointHost();
        self::itRejectsInvalidDomain();
    }

    private static function itParsesValidInstallPayload(): void
    {
        $validator = new BitrixAppInstallValidator();
        $command = $validator->validate([
            'auth' => [
                'domain' => 'Portal.Bitrix24.ru',
                'client_endpoint' => 'https://portal.bitrix24.ru/rest/',
                'member_id' => 'member-1',
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'expires_in' => 7200,
                'scope' => 'imconnector',
                'application_token' => 'app-token',
            ],
        ]);

        Assertions::assertSame('portal.bitrix24.ru', $command->portalDomain);
        Assertions::assertSame('https://portal.bitrix24.ru/rest', $command->restBaseUrl);
        Assertions::assertSame(7200, $command->expiresInSeconds);
    }

    private static function itRejectsNonHttpsClientEndpoint(): void
    {
        $validator = new BitrixAppInstallValidator();

        self::assertInvalidPayload($validator, [
            'auth' => [
                'domain' => 'portal.bitrix24.ru',
                'client_endpoint' => 'http://portal.bitrix24.ru/rest',
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'application_token' => 'app-token',
            ],
        ]);
    }

    private static function itRejectsMismatchedClientEndpointHost(): void
    {
        $validator = new BitrixAppInstallValidator();

        self::assertInvalidPayload($validator, [
            'auth' => [
                'domain' => 'portal.bitrix24.ru',
                'client_endpoint' => 'https://evil.example/rest',
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'application_token' => 'app-token',
            ],
        ]);
    }

    private static function itRejectsInvalidDomain(): void
    {
        $validator = new BitrixAppInstallValidator();

        self::assertInvalidPayload($validator, [
            'auth' => [
                'domain' => 'portal bitrix24 ru',
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'application_token' => 'app-token',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function assertInvalidPayload(BitrixAppInstallValidator $validator, array $payload): void
    {
        $thrown = false;
        try {
            $validator->validate($payload);
        } catch (InvalidArgumentException) {
            $thrown = true;
        }

        Assertions::assertTrue($thrown, 'Expected invalid payload to be rejected.');
    }
}
