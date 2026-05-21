<?php

declare(strict_types=1);

namespace OtsStats\Tests\Integration;

use InvalidArgumentException;
use OtsStats\Console\NullOutput;
use OtsStats\Repository\Database;
use OtsStats\Repository\SlowReadRepository;
use OtsStats\Service\ImportOrchestrator;
use OtsStats\Util\TimeRange;
use PHPUnit\Framework\TestCase;

final class SlowReadRepositoryTest extends TestCase
{
    private string $tmpDir;
    private Database $database;
    private SlowReadRepository $repository;
    private int $latestEnd;
    private string $testSource;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ots-stats-slow-read-test-' . uniqid('', true);
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
        $config['import_days'] = 0;
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

        $this->repository = new SlowReadRepository(
            $this->database->pdo(),
            $webConfig['sources'],
            $timeRange,
            (int) $webConfig['top_functions_limit'],
        );

        $meta = $this->repository->meta();
        $this->latestEnd = (int) $meta['latest_occurred_at'];
        $this->testSource = 'special';
    }

    protected function tearDown(): void
    {
        unset($this->repository);
        $this->database->close();
        unset($this->database);
        $this->removeDir($this->tmpDir);
    }

    public function testMetaReturnsBoundsAndCounts(): void
    {
        $meta = $this->repository->meta();

        $this->assertSame(['dispatcher', 'lua', 'sql', 'special'], $meta['sources']);
        $this->assertNotNull($meta['earliest_occurred_at']);
        $this->assertNotNull($meta['latest_occurred_at']);
        $this->assertSame($meta['latest_occurred_at'], $meta['default_end']);
        $this->assertGreaterThan(0, $meta['row_counts']['slow_events']);
    }

    public function testOverviewReturnsEventCountsAndExecutionTimes(): void
    {
        $overview = $this->repository->overview($this->testSource, $this->latestEnd, 'day');

        $this->assertSame($this->testSource, $overview['source']);
        $this->assertSame('day', $overview['range']);
        $this->assertGreaterThan(0, $overview['bucket_seconds']);
        $this->assertNotEmpty($overview['points']);
        $this->assertArrayHasKey('comparison', $overview);

        $first = $overview['points'][0];
        $this->assertArrayHasKey('t', $first);
        $this->assertArrayHasKey('event_count', $first);
        $this->assertArrayHasKey('min_execution_ms', $first);
        $this->assertArrayHasKey('max_execution_ms', $first);
        $this->assertArrayHasKey('avg_execution_ms', $first);
    }

    public function testOverviewComparisonContainsDeltaFields(): void
    {
        $overview = $this->repository->overview($this->testSource, $this->latestEnd, 'day');
        $comparison = $overview['comparison'];

        $this->assertArrayHasKey('current', $comparison);
        $this->assertArrayHasKey('previous', $comparison);
        $this->assertArrayHasKey('delta', $comparison);
        $this->assertArrayHasKey('event_count_pct', $comparison['delta']);
        $this->assertArrayHasKey('event_count', $comparison['current']);
        $this->assertArrayHasKey('unique_functions', $comparison['current']);
    }

    public function testTopFunctionsReturnsSortedByEventCount(): void
    {
        $result = $this->repository->topFunctions($this->testSource, $this->latestEnd, 'day', 'count', 10);

        $this->assertNotEmpty($result['functions']);
        $this->assertLessThanOrEqual(10, count($result['functions']));

        for ($i = 1, $n = count($result['functions']); $i < $n; ++$i) {
            $this->assertGreaterThanOrEqual(
                $result['functions'][$i]['event_count'],
                $result['functions'][$i - 1]['event_count'],
            );
        }
    }

    public function testTopFunctionsReturnsExecutionTimeStats(): void
    {
        $result = $this->repository->topFunctions($this->testSource, $this->latestEnd, 'day', 'max', 10);

        $this->assertNotEmpty($result['functions']);

        $first = $result['functions'][0];
        $this->assertArrayHasKey('description_id', $first);
        $this->assertArrayHasKey('description', $first);
        $this->assertArrayHasKey('event_count', $first);
        $this->assertArrayHasKey('min_execution_ms', $first);
        $this->assertArrayHasKey('max_execution_ms', $first);
        $this->assertArrayHasKey('avg_execution_ms', $first);
        $this->assertArrayHasKey('total_execution_ms', $first);
    }

    public function testFunctionSeriesReturnsPointsForKnownDescription(): void
    {
        $top = $this->repository->topFunctions($this->testSource, $this->latestEnd, 'day', 'count', 1);
        $descriptionId = $top['functions'][0]['description_id'];

        $series = $this->repository->functionSeries($descriptionId, $this->latestEnd, 'day');

        $this->assertSame($descriptionId, $series['description_id']);
        $this->assertNotEmpty($series['description']);
        $this->assertSame($this->testSource, $series['source']);
        $this->assertNotEmpty($series['points']);

        $first = $series['points'][0];
        $this->assertArrayHasKey('event_count', $first);
        $this->assertArrayHasKey('max_execution_ms', $first);
        $this->assertArrayHasKey('avg_execution_ms', $first);
    }

    public function testFunctionSeriesThrowsForUnknownDescription(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->functionSeries(999999, $this->latestEnd, 'hour');
    }

    public function testOverviewUsesRawFallbackWhenAggTablesEmpty(): void
    {
        $pdo = $this->database->pdo();
        $pdo->exec('PRAGMA query_only=OFF');
        $pdo->exec('DELETE FROM slow_overview_agg');
        $pdo->exec('DELETE FROM slow_function_bucket_agg');

        $overview = $this->repository->overview($this->testSource, $this->latestEnd, 'day');

        $this->assertNotEmpty($overview['points']);
        $this->assertGreaterThan(0, $overview['points'][0]['event_count']);
    }

    public function testOverviewRebucketingForSevenDayRange(): void
    {
        $hour = $this->repository->overview($this->testSource, $this->latestEnd, 'hour');
        $sevenDay = $this->repository->overview($this->testSource, $this->latestEnd, '7d');

        $this->assertGreaterThan($hour['bucket_seconds'], $sevenDay['bucket_seconds']);
        $this->assertLessThanOrEqual(1500, count($sevenDay['points']));
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
