<?php

declare(strict_types=1);

namespace ChatSync\Shared\Infrastructure\Persistence;

use ChatSync\Shared\Infrastructure\Config\AppConfig;
use PDO;

final class PdoConnectionFactory
{
    public function create(AppConfig $config): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $config->dbHost,
            $config->dbPort,
            $config->dbName,
        );

        $pdo = new PDO($dsn, $config->dbUser, $config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $pdo;
    }
}

