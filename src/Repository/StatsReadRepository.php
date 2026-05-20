<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use InvalidArgumentException;
use OtsStats\Util\TimeRange;
use PDO;

final class StatsReadRepository
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
            'SELECT MIN(reported_at) AS earliest, MAX(reported_at) AS latest FROM cpu_reports',
        )->fetch();

        $earliest = $bounds['earliest'] !== null ? (int) $bounds['earliest'] : null;
        $latest = $bounds['latest'] !== null ? (int) $bounds['latest'] : null;

        return [
            'sources' => $this->sources,
            'earliest_reported_at' => $earliest,
            'latest_reported_at' => $latest,
            'default_end' => $latest,
            'row_counts' => [
                'descriptions' => $this->count('descriptions'),
                'cpu_reports' => $this->count('cpu_reports'),
                'cpu_stats' => $this->count('cpu_stats'),
                'slow_events' => $this->count('slow_events'),
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

        if ($source === 'dispatcher') {
            $points = $this->fetchDispatcherOverview(
                $window['start'],
                $window['end'],
                $window['bucket_seconds'],
            );
        } else {
            $points = $this->fetchSourceUsageOverview(
                $source,
                $window['start'],
                $window['end'],
                $window['bucket_seconds'],
            );
        }

        return [
            'source' => $source,
            'range' => $range,
            'start' => $window['start'],
            'end' => $window['end'],
            'bucket_seconds' => $window['bucket_seconds'],
            'points' => $points,
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
            'avg' => 'avg_real_usage',
            'total' => 'total_time_ms',
            default => 'max_real_usage',
        };

        $sql = <<<SQL
            SELECT s.description_id,
                   d.description,
                   MAX(s.real_usage) AS max_real_usage,
                   AVG(s.real_usage) AS avg_real_usage,
                   SUM(s.time_ms) AS total_time_ms,
                   SUM(s.calls) AS total_calls
            FROM cpu_stats s
            INNER JOIN cpu_reports r ON r.id = s.report_id
            INNER JOIN descriptions d ON d.id = s.description_id
            WHERE r.source = :source
              AND r.reported_at > :start
              AND r.reported_at <= :end
            GROUP BY s.description_id
            ORDER BY {$orderColumn} DESC
            LIMIT :limit
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':source', $source);
        $stmt->bindValue(':start', $window['start'], PDO::PARAM_INT);
        $stmt->bindValue(':end', $window['end'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $functions = [];
        while ($row = $stmt->fetch()) {
            $functions[] = [
                'description_id' => (int) $row['description_id'],
                'description' => (string) $row['description'],
                'max_real_usage' => (float) $row['max_real_usage'],
                'avg_real_usage' => (float) $row['avg_real_usage'],
                'total_time_ms' => (int) $row['total_time_ms'],
                'total_calls' => (int) $row['total_calls'],
            ];
        }

        return [
            'source' => $source,
            'range' => $range,
            'start' => $window['start'],
            'end' => $window['end'],
            'sort' => $sort,
            'functions' => $functions,
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

        $sql = <<<SQL
            SELECT (r.reported_at / :bucket) * :bucket AS t,
                   AVG(s.real_usage) AS real_usage,
                   AVG(s.time_ms) AS time_ms,
                   AVG(s.calls) AS calls,
                   AVG(r.players_online) AS players_online
            FROM cpu_stats s
            INNER JOIN cpu_reports r ON r.id = s.report_id
            WHERE s.description_id = :description_id
              AND r.reported_at > :start
              AND r.reported_at <= :end
            GROUP BY t
            ORDER BY t
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':bucket', $window['bucket_seconds'], PDO::PARAM_INT);
        $stmt->bindValue(':description_id', $descriptionId, PDO::PARAM_INT);
        $stmt->bindValue(':start', $window['start'], PDO::PARAM_INT);
        $stmt->bindValue(':end', $window['end'], PDO::PARAM_INT);
        $stmt->execute();

        $points = [];
        while ($row = $stmt->fetch()) {
            $points[] = [
                't' => (int) $row['t'],
                'real_usage' => $row['real_usage'] !== null ? (float) $row['real_usage'] : null,
                'time_ms' => $row['time_ms'] !== null ? (float) $row['time_ms'] : null,
                'calls' => $row['calls'] !== null ? (float) $row['calls'] : null,
                'players_online' => $row['players_online'] !== null ? (float) $row['players_online'] : null,
            ];
        }

        return [
            'description_id' => $descriptionId,
            'description' => (string) $desc['description'],
            'source' => (string) $desc['source'],
            'range' => $range,
            'start' => $window['start'],
            'end' => $window['end'],
            'bucket_seconds' => $window['bucket_seconds'],
            'points' => $points,
        ];
    }

    /**
     * @return list<array{t: int, cpu_usage: ?float, players_online: ?float}>
     */
    private function fetchDispatcherOverview(int $start, int $end, int $bucket): array
    {
        $hasAggData = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM cpu_overview_agg WHERE source = \'dispatcher\' LIMIT 1',
        )->fetchColumn();

        if ($hasAggData > 0) {
            return $this->fetchDispatcherOverviewFromAgg($start, $end, $bucket);
        }

        $sql = <<<SQL
            SELECT (reported_at / :bucket) * :bucket AS t,
                   AVG(cpu_usage) AS cpu_usage,
                   AVG(players_online) AS players_online
            FROM cpu_reports
            WHERE source = 'dispatcher'
              AND reported_at > :start
              AND reported_at <= :end
            GROUP BY t
            ORDER BY t
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':bucket', $bucket, PDO::PARAM_INT);
        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':end', $end, PDO::PARAM_INT);
        $stmt->execute();

        return $this->mapOverviewPoints($stmt, true);
    }

    /**
     * @return list<array{t: int, cpu_usage: ?float, players_online: ?float}>
     */
    private function fetchDispatcherOverviewFromAgg(int $start, int $end, int $bucket): array
    {
        $sql = <<<SQL
            SELECT (bucket_time / :bucket) * :bucket AS t,
                   SUM(avg_cpu_usage * sample_count) / SUM(sample_count) AS cpu_usage,
                   SUM(avg_players_online * sample_count) / SUM(sample_count) AS players_online
            FROM cpu_overview_agg
            WHERE source = 'dispatcher'
              AND bucket_time > :start
              AND bucket_time <= :end
            GROUP BY t
            ORDER BY t
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':bucket', $bucket, PDO::PARAM_INT);
        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':end', $end, PDO::PARAM_INT);
        $stmt->execute();

        return $this->mapOverviewPoints($stmt, true);
    }

    /**
     * @return list<array{t: int, real_usage: ?float}>
     */
    private function fetchSourceUsageOverview(string $source, int $start, int $end, int $bucket): array
    {
        $sql = <<<SQL
            SELECT (r.reported_at / :bucket) * :bucket AS t,
                   SUM(s.real_usage) AS real_usage
            FROM cpu_stats s
            INNER JOIN cpu_reports r ON r.id = s.report_id
            WHERE r.source = :source
              AND r.reported_at > :start
              AND r.reported_at <= :end
            GROUP BY t
            ORDER BY t
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':bucket', $bucket, PDO::PARAM_INT);
        $stmt->bindValue(':source', $source);
        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':end', $end, PDO::PARAM_INT);
        $stmt->execute();

        $points = [];
        while ($row = $stmt->fetch()) {
            $points[] = [
                't' => (int) $row['t'],
                'real_usage' => $row['real_usage'] !== null ? (float) $row['real_usage'] : null,
            ];
        }

        return $points;
    }

    /**
     * @return list<array{t: int, cpu_usage: ?float, players_online: ?float}>
     */
    private function mapOverviewPoints(\PDOStatement $stmt, bool $includePlayers): array
    {
        $points = [];
        while ($row = $stmt->fetch()) {
            $point = [
                't' => (int) $row['t'],
                'cpu_usage' => $row['cpu_usage'] !== null ? (float) $row['cpu_usage'] : null,
            ];

            if ($includePlayers) {
                $point['players_online'] = $row['players_online'] !== null
                    ? (float) $row['players_online']
                    : null;
            }

            $points[] = $point;
        }

        return $points;
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
        if (!in_array($sort, ['max', 'avg', 'total'], true)) {
            throw new InvalidArgumentException("Invalid sort: {$sort}");
        }
    }
}
