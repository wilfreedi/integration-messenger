<?php

declare(strict_types=1);

use ChatSync\App\Bootstrap\ApplicationContainer;
use ChatSync\Shared\Infrastructure\Config\AppConfig;
use ChatSync\Shared\Infrastructure\Config\EnvironmentLoader;

require __DIR__ . '/../vendor/autoload.php';

(new EnvironmentLoader())->load(__DIR__ . '/../.env');

return new ApplicationContainer(AppConfig::fromEnvironment());

