<?php

declare(strict_types=1);

use ChatSync\App\Bootstrap\ApplicationContainer;

/** @var ApplicationContainer $container */
$container = require __DIR__ . '/bootstrap.php';

$container->pdo()->exec(
    'TRUNCATE TABLE
        audit_logs,
        outbox_messages,
        processed_events,
        message_mappings,
        deliveries,
        attachments,
        messages,
        crm_threads,
        conversations,
        contact_identities,
        contacts,
        integration_settings
     RESTART IDENTITY CASCADE',
);

fwrite(STDOUT, "Application data reset completed.\n");

