<?php

declare(strict_types=1);

use ChatSync\App\Bootstrap\ApplicationContainer;

/** @var ApplicationContainer $container */
$container = require __DIR__ . '/bootstrap.php';

$pdo = $container->pdo();
$createdAt = (new DateTimeImmutable('2026-04-07T00:00:00+00:00'))->format(DATE_ATOM);

$pdo->prepare(
    'INSERT INTO managers (id, display_name, created_at)
     VALUES (:id, :display_name, :created_at)
     ON CONFLICT (id) DO UPDATE SET display_name = EXCLUDED.display_name',
)->execute([
    'id' => '11111111-1111-4111-8111-111111111111',
    'display_name' => 'Demo Manager',
    'created_at' => $createdAt,
]);

$pdo->prepare(
    'INSERT INTO manager_accounts (id, manager_id, channel_provider, external_account_id, status, created_at)
     VALUES (:id, :manager_id, :channel_provider, :external_account_id, :status, :created_at)
     ON CONFLICT (id) DO UPDATE SET
        manager_id = EXCLUDED.manager_id,
        channel_provider = EXCLUDED.channel_provider,
        external_account_id = EXCLUDED.external_account_id,
        status = EXCLUDED.status',
)->execute([
    'id' => '22222222-2222-4222-8222-222222222222',
    'manager_id' => '11111111-1111-4111-8111-111111111111',
    'channel_provider' => 'telegram',
    'external_account_id' => 'telegram-manager-account',
    'status' => 'active',
    'created_at' => $createdAt,
]);

fwrite(STDOUT, "Demo manager account is ready: telegram-manager-account\n");

