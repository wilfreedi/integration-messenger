<?php

declare(strict_types=1);

namespace ChatSync\App\Infrastructure\Persistence;

use DateTimeImmutable;
use PDO;
use PDOStatement;

abstract class AbstractPdoRepository
{
    public function __construct(protected readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function execute(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    protected function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}

