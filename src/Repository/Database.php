<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use PDO;
use RuntimeException;

final class Database
{
    private PDO $pdo;

    public function __construct(string $dbPath, string $schemaPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: {$dir}");
        }

        $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA synchronous=NORMAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        if (!file_exists($schemaPath)) {
            throw new RuntimeException("Schema not found: {$schemaPath}");
        }

        $this->pdo->exec((string) file_get_contents($schemaPath));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
