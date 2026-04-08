<?php

declare(strict_types=1);

namespace ChatSync\Tests\Unit;

use ChatSync\App\Integration\Connector\BitrixRestClient;
use ChatSync\App\Integration\Connector\RestBitrixOpenLinesConnectorLifecycle;
use ChatSync\Tests\Support\Assertions;

final class RestBitrixOpenLinesConnectorLifecycleTest
{
    public static function run(): void
    {
        self::itAlwaysReappliesConnectorDataWhenStatusIsAlreadyReady();
        self::itRegistersAndActivatesWhenStatusIsNotReady();
    }

    private static function itAlwaysReappliesConnectorDataWhenStatusIsAlreadyReady(): void
    {
        $client = new LifecycleRecordingBitrixRestClient([
            'imconnector.status' => [
                ['result' => ['CONFIGURED' => true, 'ACTIVE' => true]],
                ['result' => ['CONFIGURED' => true, 'ACTIVE' => true]],
            ],
            'imconnector.connector.data.set' => [
                ['result' => true],
            ],
        ]);

        $lifecycle = new RestBitrixOpenLinesConnectorLifecycle(
            $client,
            'https://sync.example.com/bitrix/app',
            'https://sync.example.com/api/webhooks/bitrix/open-lines?token=secret',
        );

        $lifecycle->ensure(
            'https://portal.bitrix24.ru/rest',
            'chat_sync',
            '192',
            'access-token',
        );

        Assertions::assertSame(
            ['imconnector.status', 'imconnector.connector.data.set', 'event.get', 'imconnector.status'],
            $client->calledMethods,
        );

        $dataSetPayload = $client->payloadByMethod['imconnector.connector.data.set'][0] ?? [];
        Assertions::assertSame('chat_sync', $dataSetPayload['CONNECTOR'] ?? null);
        Assertions::assertSame(192, $dataSetPayload['LINE'] ?? null);
        Assertions::assertSame(
            'https://sync.example.com/api/webhooks/bitrix/open-lines?token=secret',
            $dataSetPayload['DATA']['URL'] ?? null,
        );
        Assertions::assertSame(
            'https://sync.example.com/api/webhooks/bitrix/open-lines?token=secret',
            $dataSetPayload['DATA']['URL_IM'] ?? null,
        );
    }

    private static function itRegistersAndActivatesWhenStatusIsNotReady(): void
    {
        $client = new LifecycleRecordingBitrixRestClient([
            'imconnector.status' => [
                ['result' => ['CONFIGURED' => false, 'ACTIVE' => false]],
                ['result' => ['CONFIGURED' => true, 'ACTIVE' => true]],
            ],
            'imconnector.register' => [
                ['result' => true],
            ],
            'imconnector.activate' => [
                ['result' => true],
            ],
            'imconnector.connector.data.set' => [
                ['result' => true],
            ],
        ]);

        $lifecycle = new RestBitrixOpenLinesConnectorLifecycle(
            $client,
            'https://sync.example.com/bitrix/app',
            'https://sync.example.com/api/webhooks/bitrix/open-lines?token=secret',
        );

        $lifecycle->ensure(
            'https://portal.bitrix24.ru/rest',
            'chat_sync',
            '192',
            'access-token',
        );

        Assertions::assertSame(
            [
                'imconnector.status',
                'imconnector.register',
                'imconnector.activate',
                'imconnector.connector.data.set',
                'event.get',
                'imconnector.status',
            ],
            $client->calledMethods,
        );
    }
}

final class LifecycleRecordingBitrixRestClient implements BitrixRestClient
{
    /** @var list<string> */
    public array $calledMethods = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $payloadByMethod = [];

    /**
     * @param array<string, list<array<string, mixed>>> $responsesByMethod
     */
    public function __construct(
        private array $responsesByMethod,
    ) {
    }

    public function call(string $baseUrl, string $method, array $payload): array
    {
        $this->calledMethods[] = $method;
        $this->payloadByMethod[$method] ??= [];
        $this->payloadByMethod[$method][] = $payload;

        $responses = $this->responsesByMethod[$method] ?? null;
        if (!is_array($responses) || $responses === []) {
            return ['result' => true];
        }

        $response = array_shift($responses);
        $this->responsesByMethod[$method] = $responses;

        return is_array($response) ? $response : ['result' => true];
    }
}
