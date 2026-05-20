<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use PDO;
use RuntimeException;

final class Database
{
    private const FUNCTION_BUCKET_SECONDS = 3600;

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

    public function endImportSession(?callable $onProgress = null): void
    {
        if (!$this->importSessionActive) {
            return;
        }

        $this->pdo->exec('PRAGMA synchronous=NORMAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->rebuildSecondaryIndexesAndAgg($onProgress);
        $this->pdo->exec('PRAGMA optimize');
        $this->importSessionActive = false;
    }

    public function rebuildSecondaryIndexesAndAgg(?callable $onProgress = null): void
    {
        $this->reportProgress($onProgress, 'Rebuilding CPU overview aggregation...');
        $started = microtime(true);
        $this->rebuildOverviewAgg();
        $this->reportProgress(
            $onProgress,
            sprintf('  Overview aggregation done in %s.', self::formatDuration(microtime(true) - $started)),
        );

        $this->reportProgress($onProgress, 'Rebuilding source usage aggregation...');
        $started = microtime(true);
        $this->rebuildSourceUsageAgg();
        $this->reportProgress(
            $onProgress,
            sprintf('  Source usage aggregation done in %s.', self::formatDuration(microtime(true) - $started)),
        );

        $this->reportProgress($onProgress, 'Rebuilding function bucket aggregation...');
        $started = microtime(true);
        $this->rebuildFunctionBucketAgg();
        $this->reportProgress(
            $onProgress,
            sprintf('  Function bucket aggregation done in %s.', self::formatDuration(microtime(true) - $started)),
        );

        $this->applySecondaryIndexes($onProgress);
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

    private function rebuildOverviewAgg(): void
    {
        $this->pdo->exec('DELETE FROM cpu_overview_agg');
        $this->pdo->exec(
            'INSERT INTO cpu_overview_agg (source, bucket_time, avg_cpu_usage, avg_players_online, sample_count)
             SELECT source,
                    (reported_at / 30) * 30 AS bucket_time,
                    AVG(cpu_usage),
                    AVG(players_online),
                    COUNT(*)
             FROM cpu_reports
             GROUP BY source, bucket_time',
        );
    }

    private function rebuildSourceUsageAgg(): void
    {
        $this->pdo->exec('DELETE FROM cpu_source_usage_agg');
        $this->pdo->exec(
            'INSERT INTO cpu_source_usage_agg (source, bucket_time, avg_report_real_usage, report_count)
             SELECT r.source,
                    (r.reported_at / 30) * 30 AS bucket_time,
                    AVG(t.report_total),
                    COUNT(DISTINCT r.id)
             FROM (
                 SELECT report_id, SUM(real_usage) AS report_total
                 FROM cpu_stats
                 GROUP BY report_id
             ) t
             INNER JOIN cpu_reports r ON r.id = t.report_id
             GROUP BY r.source, bucket_time',
        );
    }

    private function rebuildFunctionBucketAgg(): void
    {
        $bucket = self::FUNCTION_BUCKET_SECONDS;
        $this->pdo->exec('DELETE FROM cpu_function_bucket_agg');
        $this->pdo->exec(
            "INSERT INTO cpu_function_bucket_agg (
                 source, bucket_time, description_id,
                 max_real_usage, sum_real_usage, sum_time_ms, sum_calls, sample_count
             )
             SELECT r.source,
                    (r.reported_at / {$bucket}) * {$bucket} AS bucket_time,
                    s.description_id,
                    MAX(s.real_usage),
                    SUM(s.real_usage),
                    SUM(s.time_ms),
                    SUM(s.calls),
                    COUNT(*)
             FROM cpu_stats s
             INNER JOIN cpu_reports r ON r.id = s.report_id
             GROUP BY r.source, bucket_time, s.description_id",
        );
    }

    private function dropSecondaryIndexes(): void
    {
        foreach ($this->indexNames() as $name) {
            $this->pdo->exec("DROP INDEX IF EXISTS {$name}");
        }
    }

    private function applySecondaryIndexes(?callable $onProgress = null): void
    {
        $statements = $this->indexStatements();
        $total = count($statements);

        foreach ($statements as $index => $sql) {
            $name = $this->extractIndexName($sql);
            $this->reportProgress(
                $onProgress,
                sprintf('Creating index %s (%d/%d)...', $name, $index + 1, $total),
            );
            $started = microtime(true);
            $this->pdo->exec($sql);
            $this->reportProgress(
                $onProgress,
                sprintf('  %s done in %s.', $name, self::formatDuration(microtime(true) - $started)),
            );
        }
    }

    /** @return list<string> */
    private function indexStatements(): array
    {
        if (!file_exists($this->indexesPath)) {
            throw new RuntimeException("Indexes not found: {$this->indexesPath}");
        }

        $statements = [];
        foreach (explode("\n", (string) file_get_contents($this->indexesPath)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }

            $statements[] = $line;
        }

        return $statements;
    }

    private function extractIndexName(string $sql): string
    {
        if (preg_match('/CREATE\s+INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $sql, $matches) === 1) {
            return $matches[1];
        }

        return 'unknown';
    }

    private function reportProgress(?callable $onProgress, string $message): void
    {
        if ($onProgress !== null) {
            $onProgress($message);
        }
    }

    private static function formatDuration(float $seconds): string
    {
        $s = (int) round($seconds);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return sprintf('%02d:%02d:%02d', $h, $m, $sec);
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        return [
            'idx_cpu_reports_source_time',
            'idx_cpu_reports_overview',
            'idx_cpu_stats_report',
            'idx_cpu_stats_desc_time',
            'idx_cpu_stats_real_usage',
            'idx_slow_events_source_time',
            'idx_slow_events_desc',
        ];
    }
}
