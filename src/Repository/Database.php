<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use PDO;
use RuntimeException;

final class Database
{
    private PDO $pdo;
    private bool $importSessionActive = false;

    public function __construct(string $dbPath, string $schemaPath, private readonly string $indexesPath)
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

        if (!file_exists($schemaPath)) {
            throw new RuntimeException("Schema not found: {$schemaPath}");
        }

        $this->pdo->exec((string) file_get_contents($schemaPath));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function beginImportSession(): void
    {
        if ($this->importSessionActive) {
            return;
        }

        $this->dropSecondaryIndexes();
        $this->pdo->exec('PRAGMA foreign_keys=OFF');
        $this->pdo->exec('PRAGMA synchronous=OFF');
        $this->pdo->exec('PRAGMA cache_size=-262144');
        $this->pdo->exec('PRAGMA temp_store=MEMORY');
        $this->pdo->exec('PRAGMA mmap_size=268435456');

        if (getenv('OTS_SQLITE_AGGRESSIVE') === '1') {
            $this->pdo->exec('PRAGMA journal_mode=MEMORY');
            $this->pdo->exec('PRAGMA cache_size=-1048576');
            $this->pdo->exec('PRAGMA mmap_size=2147483648');
        }

        $this->importSessionActive = true;
    }

    public function endImportSession(): void
    {
        if (!$this->importSessionActive) {
            return;
        }

        $this->pdo->exec('PRAGMA synchronous=NORMAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->applySecondaryIndexes();
        $this->pdo->exec('PRAGMA optimize');
        $this->importSessionActive = false;
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function dropSecondaryIndexes(): void
    {
        foreach ($this->indexNames() as $name) {
            $this->pdo->exec("DROP INDEX IF EXISTS {$name}");
        }
    }

    private function applySecondaryIndexes(): void
    {
        if (!file_exists($this->indexesPath)) {
            throw new RuntimeException("Indexes not found: {$this->indexesPath}");
        }

        $this->pdo->exec((string) file_get_contents($this->indexesPath));
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        return [
            'idx_cpu_reports_source_time',
            'idx_cpu_stats_desc_time',
            'idx_cpu_stats_real_usage',
            'idx_slow_events_source_time',
            'idx_slow_events_desc',
        ];
    }
}
