<?php

declare(strict_types=1);

namespace OtsStats\Tests\Integration;

use OtsStats\Repository\Database;
use OtsStats\Repository\StatsReadRepository;
use OtsStats\Service\ImportOrchestrator;
use OtsStats\Util\TimeRange;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class StatsReadRepositoryTest extends TestCase
{
    private string $tmpDir;
    private Database $database;
    private StatsReadRepository $repository;
    private int $latestEnd;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ots-stats-read-test-' . uniqid('', true);
        mkdir($this->tmpDir . '/data', 0777, true);
        mkdir($this->tmpDir . '/var', 0777, true);

        $fixtures = dirname(__DIR__) . '/fixtures';
        foreach (glob($fixtures . '/*') as $file) {
            if (is_file($file)) {
                copy($file, $this->tmpDir . '/data/' . basename($file));
            }
        }

        $root = dirname(__DIR__, 2);
        $config = require $root . '/config/import.php';
        $config['dedup_days'] = 7;
        $config['batch_size'] = 100;

        $dbPath = $this->tmpDir . '/var/test.sqlite';
        $this->database = new Database($dbPath, $root . '/database/schema.sql', $root . '/database/indexes.sql');

        $orchestrator = new ImportOrchestrator(
            $this->database,
            $config,
            $this->tmpDir . '/data',
        );
        $orchestrator->run(new NullOutput(), 0);

        $webConfig = require $root . '/config/web.php';
        $timeRange = new TimeRange(
            $webConfig['ranges'],
            $webConfig['bucket_seconds'],
            (int) $webConfig['max_chart_points'],
        );

        $this->repository = new StatsReadRepository(
            $this->database->pdo(),
            $webConfig['sources'],
            $timeRange,
            (int) $webConfig['top_functions_limit'],
        );

        $meta = $this->repository->meta();
        $this->latestEnd = (int) $meta['latest_reported_at'];
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testMetaReturnsBoundsAndCounts(): void
    {
        $meta = $this->repository->meta();

        $this->assertSame(['dispatcher', 'lua', 'sql', 'special'], $meta['sources']);
        $this->assertNotNull($meta['earliest_reported_at']);
        $this->assertNotNull($meta['latest_reported_at']);
        $this->assertSame($meta['latest_reported_at'], $meta['default_end']);
        $this->assertGreaterThan(0, $meta['row_counts']['cpu_reports']);
        $this->assertGreaterThan(0, $meta['row_counts']['cpu_stats']);
    }

    public function testDispatcherOverviewReturnsCpuAndPlayers(): void
    {
        $overview = $this->repository->overview('dispatcher', $this->latestEnd, 'hour');

        $this->assertSame('dispatcher', $overview['source']);
        $this->assertSame('hour', $overview['range']);
        $this->assertGreaterThan(0, $overview['bucket_seconds']);
        $this->assertNotEmpty($overview['points']);

        $first = $overview['points'][0];
        $this->assertArrayHasKey('t', $first);
        $this->assertArrayHasKey('cpu_usage', $first);
        $this->assertArrayHasKey('players_online', $first);
    }

    public function testLuaOverviewReturnsRealUsage(): void
    {
        $overview = $this->repository->overview('lua', $this->latestEnd, 'hour');

        $this->assertSame('lua', $overview['source']);
        $this->assertNotEmpty($overview['points']);

        $first = $overview['points'][0];
        $this->assertArrayHasKey('real_usage', $first);
        $this->assertArrayNotHasKey('cpu_usage', $first);
    }

    public function testTopFunctionsReturnsSortedLuaEntries(): void
    {
        $result = $this->repository->topFunctions('lua', $this->latestEnd, 'day', 'max', 10);

        $this->assertNotEmpty($result['functions']);
        $this->assertLessThanOrEqual(10, count($result['functions']));

        for ($i = 1, $n = count($result['functions']); $i < $n; ++$i) {
            $this->assertGreaterThanOrEqual(
                $result['functions'][$i]['max_real_usage'],
                $result['functions'][$i - 1]['max_real_usage'],
            );
        }
    }

    public function testTopFunctionsReturnsSortedDispatcherEntries(): void
    {
        $result = $this->repository->topFunctions('dispatcher', $this->latestEnd, 'day', 'max', 10);

        $this->assertNotEmpty($result['functions']);
        $this->assertLessThanOrEqual(10, count($result['functions']));

        $first = $result['functions'][0];
        $this->assertArrayHasKey('description_id', $first);
        $this->assertArrayHasKey('description', $first);
        $this->assertArrayHasKey('max_real_usage', $first);

        for ($i = 1, $n = count($result['functions']); $i < $n; ++$i) {
            $this->assertGreaterThanOrEqual(
                $result['functions'][$i]['max_real_usage'],
                $result['functions'][$i - 1]['max_real_usage'],
            );
        }
    }

    public function testFunctionSeriesReturnsPointsForKnownDescription(): void
    {
        $top = $this->repository->topFunctions('dispatcher', $this->latestEnd, 'day', 'max', 1);
        $descriptionId = $top['functions'][0]['description_id'];

        $series = $this->repository->functionSeries($descriptionId, $this->latestEnd, 'hour');

        $this->assertSame($descriptionId, $series['description_id']);
        $this->assertNotEmpty($series['description']);
        $this->assertSame('dispatcher', $series['source']);
        $this->assertNotEmpty($series['points']);

        $first = $series['points'][0];
        $this->assertArrayHasKey('real_usage', $first);
        $this->assertArrayHasKey('players_online', $first);
    }

    public function testFunctionSeriesThrowsForUnknownDescription(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->functionSeries(999999, $this->latestEnd, 'hour');
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
