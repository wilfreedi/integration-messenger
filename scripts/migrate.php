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

fwrite(STDOUT, "Schema applied successfully.\n");

