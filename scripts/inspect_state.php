<?php

declare(strict_types=1);

use ChatSync\App\Bootstrap\ApplicationContainer;

/** @var ApplicationContainer $container */
$container = require __DIR__ . '/bootstrap.php';

$state = $container->debugStateController()->handle();

echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;

