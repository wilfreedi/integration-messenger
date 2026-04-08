<?php

declare(strict_types=1);

use ChatSync\App\Bootstrap\ApplicationContainer;

/** @var ApplicationContainer $container */
$container = require __DIR__ . '/bootstrap.php';

$schema = file_get_contents(__DIR__ . '/../database/schema.sql');

if ($schema === false) {
    fwrite(STDERR, "Unable to read database/schema.sql\n");
    exit(1);
}

$container->pdo()->exec($schema);
$container->pdo()->exec('ALTER TABLE bitrix_app_installs ADD COLUMN IF NOT EXISTS oauth_client_id VARCHAR(255) NULL');
$container->pdo()->exec('ALTER TABLE bitrix_app_installs ADD COLUMN IF NOT EXISTS oauth_client_secret VARCHAR(255) NULL');
$container->pdo()->exec('ALTER TABLE bitrix_app_installs ADD COLUMN IF NOT EXISTS oauth_server_endpoint TEXT NULL');

fwrite(STDOUT, "Schema applied successfully.\n");
