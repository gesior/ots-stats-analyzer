<?php

declare(strict_types=1);

namespace OtsStats\Tests\Integration;

use OtsStats\Repository\Database;
use OtsStats\Service\ImportOrchestrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

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
        $root = dirname(__DIR__, 2);
        $config = require $root . '/config/import.php';
        $config['dedup_days'] = 7;
        $config['batch_size'] = 100;

        $dbPath = $this->tmpDir . '/var/test.sqlite';
        $database = new Database($dbPath, $root . '/database/schema.sql', $root . '/database/indexes.sql');

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
        $root = dirname(__DIR__, 2);
        $config = require $root . '/config/import.php';
        $config['dedup_days'] = 7;
        $config['batch_size'] = 100;

        $dbPath = $this->tmpDir . '/var/test.sqlite';
        $database = new Database($dbPath, $root . '/database/schema.sql', $root . '/database/indexes.sql');

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
