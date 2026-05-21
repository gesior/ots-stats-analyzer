<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use InvalidArgumentException;
use OtsStats\Util\TimeRange;
use PDO;

final class SlowReadRepository
{
    /** @param list<string> $sources */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $sources,
        private readonly TimeRange $timeRange,
        private readonly int $topFunctionsLimit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $bounds = $this->pdo->query(
            'SELECT MIN(occurred_at) AS earliest, MAX(occurred_at) AS latest FROM slow_events',
        )->fetch();

        $earliest = $bounds['earliest'] !== null ? (int) $bounds['earliest'] : null;
        $latest = $bounds['latest'] !== null ? (int) $bounds['latest'] : null;

        return [
            'sources' => $this->sources,
            'earliest_occurred_at' => $earliest,
            'latest_occurred_at' => $latest,
            'default_end' => $latest,
            'row_counts' => [
                'descriptions' => $this->count('descriptions'),
                'slow_events' => $this->count('slow_events'),
                'slow_overview_agg' => $this->count('slow_overview_agg'),
                'slow_function_bucket_agg' => $this->count('slow_function_bucket_agg'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(string $source, int $end, string $range): array
    {
        $this->validateSource($source);
        $window = $this->timeRange->resolve($end, $range);

        $points = $this->hasSlowOverviewAgg($source)
            ? $this->fetchOverviewFromAgg($source, $window['start'], $window['end'], $window['bucket_seconds'])
            : $this->fetchOverviewFromRaw($source, $window['start'], $window['end'], $window['bucket_seconds']);

        $comparison = $this->buildComparison(
            $source,
            $window['start'],
            $window['end'],
            $this->hasSlowOverviewAgg($source),
        );

        return [
            'source' => $source,
            'range' => $range,
            'start' => $window['start'],
            'end' => $window['end'],
            'bucket_seconds' => $window['bucket_seconds'],
            'points' => $points,
            'comparison' => $comparison,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function topFunctions(string $source, int $end, string $range, string $sort, int $limit): array
    {
        $this->validateSource($source);
        $this->validateSort($sort);
        $window = $this->timeRange->resolve($end, $range);
        $limit = max(1, min($limit, $this->topFunctionsLimit));

        $orderColumn = match ($sort) {
            'max' => 'max_execution_ms',
            'avg' => 'avg_execution_ms',
            'total' => 'total_execution_ms',
            default => 'event_count',
        };

        if ($this->hasSlowFunctionBucketAgg($source)) {
            return $this->topFunctionsFromAgg($source, $window, $range, $sort, $limit, $orderColumn);
        }

        $sql = <<<SQL
            SELECT e.description_id,
                   d.description,
                   COUNT(*) AS event_count,
                   MIN(e.execution_ms) AS min_execution_ms,
                   MAX(e.execution_ms) AS max_execution_ms,
                   AVG(e.execution_ms) AS avg_execution_ms,
                   SUM(e.execution_ms) AS total_execution_ms
            FROM slow_events e
            INNER JOIN descriptions d ON d.id = e.description_id
            WHERE e.source = :source
              AND e.occurred_at > :start
              AND e.occurred_at <= :end
            GROUP BY e.description_id
            ORDER BY {$orderColumn} DESC
            LIMIT :limit
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':source', $source);
        $stmt->bindValue(':start', $window['start'], PDO::PARAM_INT);
        $stmt->bindValue(':end', $window['end'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'source' => $source,
            'range' => $range,
            'start' => $window['start'],
            'end' => $window['end'],
            'sort' => $sort,
            'functions' => $this->mapFunctionRows($stmt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function functionSeries(int $descriptionId, int $end, string $range): array
    {
        $window = $this->timeRange->resolve($end, $range);

        $descStmt = $this->pdo->prepare(
            'SELECT id, source, description FROM descriptions WHERE id = :id',
        );
        $descStmt->execute(['id' => $descriptionId]);
        $desc = $descStmt->fetch();

        if ($desc === false) {
            throw new InvalidArgumentException("Unknown description_id: {$descriptionId}");
        }

        $source = (string) $desc['source'];

        $points = $this->hasSlowFunctionBucketAgg($source)
            ? $this->fetchFunctionSeriesFromAgg(
                $descriptionId,
                $window['start'],
                $window['end'],
                $window['bucket_seconds'],
            )
            : $this->fetchFunctionSeriesFromRaw(
                $descriptionId,
                $window['start'],
                $window['end'],
                $window['bucket_seconds'],
            );

        return [
            'description_id' => $descriptionId,
            'description' => $this->sanitizeDescription((string) $desc['description']),
            'source' => $source,
            'range' => $range,
            'start' => $window['start'],
            'end' => $window['end'],
            'bucket_seconds' => $window['bucket_seconds'],
            'points' => $points,
        ];
    }

    /**
     * @return list<array{
     *     t: int,
     *     event_count: int,
     *     min_execution_ms: ?int,
     *     max_execution_ms: ?int,
     *     avg_execution_ms: ?float
     * }>
     */
    private function fetchOverviewFromAgg(string $source, int $start, int $end, int $bucket): array
    {
        $sql = <<<SQL
            SELECT (bucket_time / :bucket) * :bucket AS t,
                   SUM(event_count) AS event_count,
                   MIN(min_execution_ms) AS min_execution_ms,
                   MAX(max_execution_ms) AS max_execution_ms,
                   SUM(sum_execution_ms) AS sum_execution_ms
            FROM slow_overview_agg
            WHERE source = :source
              AND bucket_time > :start
              AND bucket_time <= :end
            GROUP BY t
            ORDER BY t
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':bucket', $bucket, PDO::PARAM_INT);
        $stmt->bindValue(':source', $source);
        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':end', $end, PDO::PARAM_INT);
        $stmt->execute();

        return $this->mapSlowPoints($stmt);
    }

    /**
     * @return list<array{
     *     t: int,
     *     event_count: int,
     *     min_execution_ms: ?int,
     *     max_execution_ms: ?int,
     *     avg_execution_ms: ?float
     * }>
     */
    private function fetchOverviewFromRaw(string $source, int $start, int $end, int $bucket): array
    {
        $sql = <<<SQL
            SELECT (occurred_at / :bucket) * :bucket AS t,
                   COUNT(*) AS event_count,
                   MIN(execution_ms) AS min_execution_ms,
                   MAX(execution_ms) AS max_execution_ms,
                   SUM(execution_ms) AS sum_execution_ms
            FROM slow_events
            WHERE source = :source
              AND occurred_at > :start
              AND occurred_at <= :end
            GROUP BY t
            ORDER BY t
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':bucket', $bucket, PDO::PARAM_INT);
        $stmt->bindValue(':source', $source);
        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':end', $end, PDO::PARAM_INT);
        $stmt->execute();

        return $this->mapSlowPoints($stmt);
    }

    /**
     * @param array{start: int, end: int} $window
     * @return array<string, mixed>
     */
    private function topFunctionsFromAgg(
        string $source,
        array $window,
        string $range,
        string $sort,
        int $limit,
        string $orderColumn,
    ): array {
        $sql = <<<SQL
            SELECT a.description_id,
                   d.description,
                   SUM(a.event_count) AS event_count,
                   MIN(a.min_execution_ms) AS min_execution_ms,
                   MAX(a.max_execution_ms) AS max_execution_ms,
                   SUM(a.sum_execution_ms) * 1.0 / SUM(a.event_count) AS avg_execution_ms,
                   SUM(a.sum_execution_ms) AS total_execution_ms
            FROM slow_function_bucket_agg a
            INNER JOIN descriptions d ON d.id = a.description_id
            WHERE a.source = :source
              AND a.bucket_time > :start
              AND a.bucket_time <= :end
            GROUP BY a.description_id
            ORDER BY {$orderColumn} DESC
            LIMIT :limit
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':source', $source);
        $stmt->bindValue(':start', $window['start'], PDO::PARAM_INT);
        $stmt->bindValue(':end', $window['end'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'source' => $source,
            'range' => $range,
            'start' => $window['start'],
            'end' => $window['end'],
            'sort' => $sort,
            'functions' => $this->mapFunctionRows($stmt),
        ];
    }

    /**
     * @return list<array{
     *     t: int,
     *     event_count: int,
     *     min_execution_ms: ?int,
     *     max_execution_ms: ?int,
     *     avg_execution_ms: ?float
     * }>
     */
    private function fetchFunctionSeriesFromAgg(int $descriptionId, int $start, int $end, int $bucket): array
    {
        $sql = <<<SQL
            SELECT (bucket_time / :bucket) * :bucket AS t,
                   SUM(event_count) AS event_count,
                   MIN(min_execution_ms) AS min_execution_ms,
                   MAX(max_execution_ms) AS max_execution_ms,
                   SUM(sum_execution_ms) AS sum_execution_ms
            FROM slow_function_bucket_agg
            WHERE description_id = :description_id
              AND bucket_time > :start
              AND bucket_time <= :end
            GROUP BY t
            ORDER BY t
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':bucket', $bucket, PDO::PARAM_INT);
        $stmt->bindValue(':description_id', $descriptionId, PDO::PARAM_INT);
        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':end', $end, PDO::PARAM_INT);
        $stmt->execute();

        return $this->mapSlowPoints($stmt);
    }

    /**
     * @return list<array{
     *     t: int,
     *     event_count: int,
     *     min_execution_ms: ?int,
     *     max_execution_ms: ?int,
     *     avg_execution_ms: ?float
     * }>
     */
    private function fetchFunctionSeriesFromRaw(int $descriptionId, int $start, int $end, int $bucket): array
    {
        $sql = <<<SQL
            SELECT (occurred_at / :bucket) * :bucket AS t,
                   COUNT(*) AS event_count,
                   MIN(execution_ms) AS min_execution_ms,
                   MAX(execution_ms) AS max_execution_ms,
                   SUM(execution_ms) AS sum_execution_ms
            FROM slow_events
            WHERE description_id = :description_id
              AND occurred_at > :start
              AND occurred_at <= :end
            GROUP BY t
            ORDER BY t
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':bucket', $bucket, PDO::PARAM_INT);
        $stmt->bindValue(':description_id', $descriptionId, PDO::PARAM_INT);
        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':end', $end, PDO::PARAM_INT);
        $stmt->execute();

        return $this->mapSlowPoints($stmt);
    }

    /**
     * @return array{
     *     previous_start: int,
     *     previous_end: int,
     *     current: array{event_count: int, max_execution_ms: ?int, avg_execution_ms: ?float, unique_functions: int},
     *     previous: array{event_count: int, max_execution_ms: ?int, avg_execution_ms: ?float, unique_functions: int},
     *     delta: array{event_count_pct: ?float, max_execution_ms_pct: ?float, avg_execution_ms_pct: ?float}
     * }
     */
    private function buildComparison(string $source, int $start, int $end, bool $useAgg): array
    {
        $duration = $end - $start;
        $previousStart = $start - $duration;
        $previousEnd = $start;

        $current = $this->summarizeWindow($source, $start, $end, $useAgg);
        $previous = $this->summarizeWindow($source, $previousStart, $previousEnd, $useAgg);

        return [
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'current' => $current,
            'previous' => $previous,
            'delta' => [
                'event_count_pct' => $this->percentDelta($previous['event_count'], $current['event_count']),
                'max_execution_ms_pct' => $this->percentDelta(
                    $previous['max_execution_ms'],
                    $current['max_execution_ms'],
                ),
                'avg_execution_ms_pct' => $this->percentDelta(
                    $previous['avg_execution_ms'],
                    $current['avg_execution_ms'],
                ),
            ],
        ];
    }

    /**
     * @return array{event_count: int, max_execution_ms: ?int, avg_execution_ms: ?float, unique_functions: int}
     */
    private function summarizeWindow(string $source, int $start, int $end, bool $useAgg): array
    {
        if ($useAgg) {
            $overviewStmt = $this->pdo->prepare(
                'SELECT SUM(event_count) AS event_count,
                        MAX(max_execution_ms) AS max_execution_ms,
                        SUM(sum_execution_ms) AS sum_execution_ms
                 FROM slow_overview_agg
                 WHERE source = :source
                   AND bucket_time > :start
                   AND bucket_time <= :end',
            );
            $overviewStmt->execute([
                'source' => $source,
                'start' => $start,
                'end' => $end,
            ]);
            $overview = $overviewStmt->fetch();

            $uniqueStmt = $this->pdo->prepare(
                'SELECT COUNT(DISTINCT description_id) AS unique_functions
                 FROM slow_function_bucket_agg
                 WHERE source = :source
                   AND bucket_time > :start
                   AND bucket_time <= :end',
            );
            $uniqueStmt->execute([
                'source' => $source,
                'start' => $start,
                'end' => $end,
            ]);
            $unique = $uniqueStmt->fetch();
        } else {
            $overviewStmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS event_count,
                        MAX(execution_ms) AS max_execution_ms,
                        SUM(execution_ms) AS sum_execution_ms
                 FROM slow_events
                 WHERE source = :source
                   AND occurred_at > :start
                   AND occurred_at <= :end',
            );
            $overviewStmt->execute([
                'source' => $source,
                'start' => $start,
                'end' => $end,
            ]);
            $overview = $overviewStmt->fetch();

            $uniqueStmt = $this->pdo->prepare(
                'SELECT COUNT(DISTINCT description_id) AS unique_functions
                 FROM slow_events
                 WHERE source = :source
                   AND occurred_at > :start
                   AND occurred_at <= :end',
            );
            $uniqueStmt->execute([
                'source' => $source,
                'start' => $start,
                'end' => $end,
            ]);
            $unique = $uniqueStmt->fetch();
        }

        $eventCount = (int) ($overview['event_count'] ?? 0);
        $sumMs = (int) ($overview['sum_execution_ms'] ?? 0);

        return [
            'event_count' => $eventCount,
            'max_execution_ms' => $overview['max_execution_ms'] !== null
                ? (int) $overview['max_execution_ms']
                : null,
            'avg_execution_ms' => $eventCount > 0 ? $sumMs / $eventCount : null,
            'unique_functions' => (int) ($unique['unique_functions'] ?? 0),
        ];
    }

    /**
     * @return list<array{
     *     description_id: int,
     *     description: string,
     *     event_count: int,
     *     min_execution_ms: int,
     *     max_execution_ms: int,
     *     avg_execution_ms: float,
     *     total_execution_ms: int
     * }>
     */
    private function mapFunctionRows(\PDOStatement $stmt): array
    {
        $functions = [];
        while ($row = $stmt->fetch()) {
            $functions[] = [
                'description_id' => (int) $row['description_id'],
                'description' => $this->sanitizeDescription((string) $row['description']),
                'event_count' => (int) $row['event_count'],
                'min_execution_ms' => (int) $row['min_execution_ms'],
                'max_execution_ms' => (int) $row['max_execution_ms'],
                'avg_execution_ms' => (float) $row['avg_execution_ms'],
                'total_execution_ms' => (int) $row['total_execution_ms'],
            ];
        }

        return $functions;
    }

    /**
     * @return list<array{
     *     t: int,
     *     event_count: int,
     *     min_execution_ms: ?int,
     *     max_execution_ms: ?int,
     *     avg_execution_ms: ?float
     * }>
     */
    private function mapSlowPoints(\PDOStatement $stmt): array
    {
        $points = [];
        while ($row = $stmt->fetch()) {
            $eventCount = (int) $row['event_count'];
            $sumMs = (int) $row['sum_execution_ms'];

            $points[] = [
                't' => (int) $row['t'],
                'event_count' => $eventCount,
                'min_execution_ms' => $row['min_execution_ms'] !== null
                    ? (int) $row['min_execution_ms']
                    : null,
                'max_execution_ms' => $row['max_execution_ms'] !== null
                    ? (int) $row['max_execution_ms']
                    : null,
                'avg_execution_ms' => $eventCount > 0 ? $sumMs / $eventCount : null,
            ];
        }

        return $points;
    }

    private function hasSlowOverviewAgg(string $source): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM slow_overview_agg WHERE source = :source LIMIT 1',
        );
        $stmt->execute(['source' => $source]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function hasSlowFunctionBucketAgg(string $source): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM slow_function_bucket_agg WHERE source = :source LIMIT 1',
        );
        $stmt->execute(['source' => $source]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function percentDelta(?float $previous, ?float $current): ?float
    {
        if ($previous === null || $current === null || $previous == 0.0) {
            return null;
        }

        return (($current - $previous) / $previous) * 100.0;
    }

    private function count(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    private function validateSource(string $source): void
    {
        if (!in_array($source, $this->sources, true)) {
            throw new InvalidArgumentException("Invalid source: {$source}");
        }
    }

    private function validateSort(string $sort): void
    {
        if (!in_array($sort, ['count', 'max', 'avg', 'total'], true)) {
            throw new InvalidArgumentException("Invalid sort: {$sort}");
        }
    }

    private function sanitizeDescription(string $description): string
    {
        return mb_convert_encoding($description, 'UTF-8', 'UTF-8');
    }
}
