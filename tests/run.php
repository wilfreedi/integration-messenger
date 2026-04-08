<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run `composer dump-autoload` first.\n");
    exit(1);
}

require $autoload;
require __DIR__ . '/Support/TestEnvironment.php';
require __DIR__ . '/Unit/BitrixAppInstallValidatorTest.php';
require __DIR__ . '/Unit/BitrixTokenRefreshResilienceTest.php';
require __DIR__ . '/Unit/BitrixOpenLinesApiTest.php';
require __DIR__ . '/Unit/BitrixOpenLinesConnectorTest.php';
require __DIR__ . '/Unit/RestBitrixOpenLinesConnectorLifecycleTest.php';
require __DIR__ . '/Unit/BitrixOpenLinesWebhookValidatorTest.php';
require __DIR__ . '/Unit/GatewayTelegramChannelConnectorTest.php';
require __DIR__ . '/Unit/PanelAccessGuardTest.php';
require __DIR__ . '/Unit/SyncInboundChannelMessageHandlerTest.php';
require __DIR__ . '/Unit/SyncOutboundCrmMessageHandlerTest.php';

$tests = [
    ChatSync\Tests\Unit\BitrixAppInstallValidatorTest::class,
    ChatSync\Tests\Unit\BitrixTokenRefreshResilienceTest::class,
    ChatSync\Tests\Unit\BitrixOpenLinesApiTest::class,
    ChatSync\Tests\Unit\BitrixOpenLinesConnectorTest::class,
    ChatSync\Tests\Unit\RestBitrixOpenLinesConnectorLifecycleTest::class,
    ChatSync\Tests\Unit\BitrixOpenLinesWebhookValidatorTest::class,
    ChatSync\Tests\Unit\GatewayTelegramChannelConnectorTest::class,
    ChatSync\Tests\Unit\PanelAccessGuardTest::class,
    ChatSync\Tests\Unit\SyncInboundChannelMessageHandlerTest::class,
    ChatSync\Tests\Unit\SyncOutboundCrmMessageHandlerTest::class,
];

$failures = [];

foreach ($tests as $testClass) {
    try {
        $testClass::run();
        fwrite(STDOUT, sprintf("[OK] %s\n", $testClass));
    } catch (Throwable $throwable) {
        $failures[] = [$testClass, $throwable];
        fwrite(STDOUT, sprintf("[FAIL] %s: %s\n", $testClass, $throwable->getMessage()));
    }
}

if ($failures !== []) {
    exit(1);
}

fwrite(STDOUT, "All tests passed.\n");
