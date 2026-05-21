<?php

declare(strict_types=1);

namespace OtsStats\Tests\Integration;

use OtsStats\Repository\Database;
use OtsStats\Service\ImportOrchestrator;
use PHPUnit\Framework\TestCase;
use OtsStats\Console\NullOutput;

final class ImportIntegrationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ots-stats-test-' . uniqid('', true);
        mkdir($this->tmpDir . '/data', 0777, true);
        mkdir($this->tmpDir . '/var', 0777, true);

        $fixtures = dirname(__DIR__) . '/fixtures';
        foreach (glob($fixtures . '/*') as $file) {
            if (is_file($file)) {
                copy($file, $this->tmpDir . '/data/' . basename($file));
            }
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testImportAndReimportIsIdempotent(): void
    {
        $config = $this->baseConfig();

        $dbPath = $this->tmpDir . '/var/test.sqlite';
        $database = new Database($dbPath, dirname(__DIR__, 2) . '/database/schema.sql', dirname(__DIR__, 2) . '/database/indexes.sql');

        $orchestrator = new ImportOrchestrator(
            $database,
            $config,
            $this->tmpDir . '/data',
        );

        $orchestrator->run(new NullOutput(), 0);

        $pdo = $database->pdo();
        $slowCount = (int) $pdo->query('SELECT COUNT(*) FROM slow_events')->fetchColumn();
        $cpuStatsCount = (int) $pdo->query('SELECT COUNT(*) FROM cpu_stats')->fetchColumn();

        $this->assertGreaterThan(0, $slowCount);
        $this->assertGreaterThan(0, $cpuStatsCount);

        $orchestrator->run(new NullOutput(), 0);

        $this->assertSame($slowCount, (int) $pdo->query('SELECT COUNT(*) FROM slow_events')->fetchColumn());
        $this->assertSame($cpuStatsCount, (int) $pdo->query('SELECT COUNT(*) FROM cpu_stats')->fetchColumn());
    }

    public function testImportPopulatesCpuOverviewAgg(): void
    {
        $config = $this->baseConfig();

        $dbPath = $this->tmpDir . '/var/test.sqlite';
        $database = new Database($dbPath, dirname(__DIR__, 2) . '/database/schema.sql', dirname(__DIR__, 2) . '/database/indexes.sql');

        $orchestrator = new ImportOrchestrator(
            $database,
            $config,
            $this->tmpDir . '/data',
        );

        $orchestrator->run(new NullOutput(), 0);

        $pdo = $database->pdo();

        $aggCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM cpu_overview_agg WHERE source = 'dispatcher'",
        )->fetchColumn();
        $this->assertGreaterThan(0, $aggCount);

        $rawAvg = (float) $pdo->query(
            "SELECT AVG(cpu_usage) FROM cpu_reports WHERE source = 'dispatcher' AND cpu_usage IS NOT NULL",
        )->fetchColumn();

        $aggAvg = (float) $pdo->query(
            "SELECT SUM(avg_cpu_usage * sample_count) / SUM(sample_count)
             FROM cpu_overview_agg
             WHERE source = 'dispatcher' AND avg_cpu_usage IS NOT NULL",
        )->fetchColumn();

        $this->assertEqualsWithDelta($rawAvg, $aggAvg, 0.001);
    }

    public function testImportPopulatesSourceUsageAgg(): void
    {
        $config = $this->baseConfig();

        $dbPath = $this->tmpDir . '/var/test.sqlite';
        $database = new Database($dbPath, dirname(__DIR__, 2) . '/database/schema.sql', dirname(__DIR__, 2) . '/database/indexes.sql');

        $orchestrator = new ImportOrchestrator(
            $database,
            $config,
            $this->tmpDir . '/data',
        );

        $orchestrator->run(new NullOutput(), 0);

        $pdo = $database->pdo();

        $aggCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM cpu_source_usage_agg WHERE source = 'lua'",
        )->fetchColumn();
        $this->assertGreaterThan(0, $aggCount);

        $rawAvg = (float) $pdo->query(
            'SELECT AVG(t.report_total)
             FROM (
                 SELECT s.report_id, SUM(s.real_usage) AS report_total
                 FROM cpu_stats s
                 INNER JOIN cpu_reports r ON r.id = s.report_id
                 WHERE r.source = \'lua\'
                 GROUP BY s.report_id
             ) t',
        )->fetchColumn();

        $aggAvg = (float) $pdo->query(
            "SELECT SUM(avg_report_real_usage * report_count) / SUM(report_count)
             FROM cpu_source_usage_agg
             WHERE source = 'lua'",
        )->fetchColumn();

        $this->assertEqualsWithDelta($rawAvg, $aggAvg, 0.001);
    }

    public function testImportPopulatesFunctionBucketAgg(): void
    {
        $config = $this->baseConfig();

        $dbPath = $this->tmpDir . '/var/test.sqlite';
        $database = new Database($dbPath, dirname(__DIR__, 2) . '/database/schema.sql', dirname(__DIR__, 2) . '/database/indexes.sql');

        $orchestrator = new ImportOrchestrator(
            $database,
            $config,
            $this->tmpDir . '/data',
        );

        $orchestrator->run(new NullOutput(), 0);

        $pdo = $database->pdo();

        $aggCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM cpu_function_bucket_agg WHERE source = 'dispatcher'",
        )->fetchColumn();
        $this->assertGreaterThan(0, $aggCount);

        $rawMax = (float) $pdo->query(
            'SELECT MAX(s.real_usage)
             FROM cpu_stats s
             INNER JOIN cpu_reports r ON r.id = s.report_id
             WHERE r.source = \'dispatcher\'',
        )->fetchColumn();

        $aggMax = (float) $pdo->query(
            "SELECT MAX(max_real_usage) FROM cpu_function_bucket_agg WHERE source = 'dispatcher'",
        )->fetchColumn();

        $this->assertEqualsWithDelta($rawMax, $aggMax, 0.001);
    }

    public function testImportPopulatesSlowOverviewAgg(): void
    {
        $config = $this->baseConfig();

        $dbPath = $this->tmpDir . '/var/test.sqlite';
        $database = new Database($dbPath, dirname(__DIR__, 2) . '/database/schema.sql', dirname(__DIR__, 2) . '/database/indexes.sql');

        $orchestrator = new ImportOrchestrator(
            $database,
            $config,
            $this->tmpDir . '/data',
        );

        $orchestrator->run(new NullOutput(), 0);

        $pdo = $database->pdo();

        $aggCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM slow_overview_agg WHERE source = 'special'",
        )->fetchColumn();
        $this->assertGreaterThan(0, $aggCount);

        $rawCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM slow_events WHERE source = 'special'",
        )->fetchColumn();

        $aggEventCount = (int) $pdo->query(
            "SELECT SUM(event_count) FROM slow_overview_agg WHERE source = 'special'",
        )->fetchColumn();

        $this->assertSame($rawCount, $aggEventCount);
    }

    public function testImportDaysFilterExcludesOldEvents(): void
    {
        $this->removeDir($this->tmpDir . '/data');
        mkdir($this->tmpDir . '/data', 0777, true);

        $oldTs = time() - (60 * 86400);
        $recentTs = time() - (2 * 86400);
        $content = $this->slowLine($oldTs, 'old-event') . "\n"
            . $this->slowLine($recentTs, 'recent-event') . "\n";
        file_put_contents($this->tmpDir . '/data/special_slow.log', $content);

        $config = $this->baseConfig();
        $config['import_days'] = 7;
        $config['sources'] = ['special'];

        $database = new Database(
            $this->tmpDir . '/var/test.sqlite',
            dirname(__DIR__, 2) . '/database/schema.sql',
            dirname(__DIR__, 2) . '/database/indexes.sql',
        );

        $orchestrator = new ImportOrchestrator($database, $config, $this->tmpDir . '/data');
        $orchestrator->run(new NullOutput(), 0);

        $pdo = $database->pdo();
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM slow_events')->fetchColumn());
    }

    public function testReplacedFileIsImportedFromStart(): void
    {
        $this->removeDir($this->tmpDir . '/data');
        mkdir($this->tmpDir . '/data', 0777, true);

        $firstTs = time() - (2 * 86400);
        file_put_contents(
            $this->tmpDir . '/data/special_slow.log',
            $this->slowLine($firstTs, 'first-day') . "\n",
        );

        $config = $this->baseConfig();
        $config['sources'] = ['special'];

        $database = new Database(
            $this->tmpDir . '/var/test.sqlite',
            dirname(__DIR__, 2) . '/database/schema.sql',
            dirname(__DIR__, 2) . '/database/indexes.sql',
        );
        $orchestrator = new ImportOrchestrator($database, $config, $this->tmpDir . '/data');
        $orchestrator->run(new NullOutput(), 0);

        $secondTs = time() - 86400;
        file_put_contents(
            $this->tmpDir . '/data/special_slow.log',
            $this->slowLine($secondTs, 'replacement-day') . "\n",
        );

        $orchestrator->run(new NullOutput(), 0);

        $pdo = $database->pdo();
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM slow_events')->fetchColumn());
    }

    public function testSeparateDailyFilesHaveSeparateCheckpoints(): void
    {
        $this->removeDir($this->tmpDir . '/data');
        mkdir($this->tmpDir . '/data', 0777, true);

        $tsA = time() - (3 * 86400);
        $tsB = time() - (2 * 86400);
        file_put_contents(
            $this->tmpDir . '/data/special_slow_2026-05-19.log',
            $this->slowLine($tsA, 'day-a') . "\n",
        );
        file_put_contents(
            $this->tmpDir . '/data/special_slow_2026-05-20.log',
            $this->slowLine($tsB, 'day-b') . "\n",
        );

        $config = $this->baseConfig();
        $config['sources'] = ['special'];

        $database = new Database(
            $this->tmpDir . '/var/test.sqlite',
            dirname(__DIR__, 2) . '/database/schema.sql',
            dirname(__DIR__, 2) . '/database/indexes.sql',
        );
        $orchestrator = new ImportOrchestrator($database, $config, $this->tmpDir . '/data');
        $orchestrator->run(new NullOutput(), 0);

        $pdo = $database->pdo();
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM import_files')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM slow_events')->fetchColumn());
    }

    public function testRollingCpuLogOutsideImportWindowDoesNotSaveCheckpoint(): void
    {
        $this->removeDir($this->tmpDir . '/data');
        mkdir($this->tmpDir . '/data', 0777, true);

        $oldTs = time() - (120 * 86400);
        $content = $this->cpuReport($oldTs);
        for ($i = 0; $i < 20; ++$i) {
            $content .= $this->cpuReport($oldTs + ($i * 30));
        }
        file_put_contents($this->tmpDir . '/data/dispatcher.log', $content);

        $config = $this->baseConfig();
        $config['import_days'] = 30;
        $config['sources'] = ['dispatcher'];

        $database = new Database(
            $this->tmpDir . '/var/test.sqlite',
            dirname(__DIR__, 2) . '/database/schema.sql',
            dirname(__DIR__, 2) . '/database/indexes.sql',
        );
        $orchestrator = new ImportOrchestrator($database, $config, $this->tmpDir . '/data');
        $orchestrator->run(new NullOutput(), 0);

        $pdo = $database->pdo();
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM cpu_stats')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM import_files')->fetchColumn());

        $config['import_days'] = 0;
        $orchestrator = new ImportOrchestrator($database, $config, $this->tmpDir . '/data');
        $orchestrator->run(new NullOutput(), 0);

        $this->assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM cpu_stats')->fetchColumn());
    }

    public function testRollingCpuLogImportsRecentSectionWithinWindow(): void
    {
        $this->removeDir($this->tmpDir . '/data');
        mkdir($this->tmpDir . '/data', 0777, true);

        $oldTs = time() - (120 * 86400);
        $recentTs = time() - (5 * 86400);
        $content = '';
        for ($i = 0; $i < 30; ++$i) {
            $content .= $this->cpuReport($oldTs + ($i * 30));
        }
        $content .= $this->cpuReport($recentTs);

        file_put_contents($this->tmpDir . '/data/dispatcher.log', $content);

        $config = $this->baseConfig();
        $config['import_days'] = 30;
        $config['sources'] = ['dispatcher'];

        $database = new Database(
            $this->tmpDir . '/var/test.sqlite',
            dirname(__DIR__, 2) . '/database/schema.sql',
            dirname(__DIR__, 2) . '/database/indexes.sql',
        );
        $orchestrator = new ImportOrchestrator($database, $config, $this->tmpDir . '/data');
        $orchestrator->run(new NullOutput(), 0);

        $pdo = $database->pdo();
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM cpu_reports')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM cpu_stats')->fetchColumn());
    }

    /** @return array<string, mixed> */
    private function baseConfig(): array
    {
        $root = dirname(__DIR__, 2);
        $config = require $root . '/config/import.php';
        $config['dedup_days'] = 7;
        $config['import_days'] = 0;
        $config['batch_size'] = 100;

        return $config;
    }

    private function slowLine(int $timestamp, string $label): string
    {
        return sprintf(
            '[%s] Execution time: 10 ms - %s - detail',
            date('j/n/Y G:i:s', $timestamp),
            $label,
        );
    }

    private function cpuReport(int $timestamp): string
    {
        return sprintf(
            "[%s]\nThread: 1 Cpu usage: 1.0%% Idle: 99.0%% Other: 0.0%% Players online: 0\n"
            . " Time (ms)     Calls     Rel usage %%    Real usage %% Description\n"
            . "     1000         1       50.00000%%       50.00000%% testFunction\n\n",
            date('j/n/Y G:i:s', $timestamp),
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
