<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use OtsStats\Util\DedupKey;
use PDO;
use PDOStatement;

final class CpuReportRepository
{
    private readonly PDOStatement $insertReportStmt;
    private readonly MultiRowInserter $statsInserter;

    public function __construct(
        private readonly PDO $pdo,
    ) {
        $this->insertReportStmt = $pdo->prepare(
            'INSERT INTO cpu_reports (source, reported_at, thread_id, cpu_usage, idle, other, players_online)
             VALUES (:source, :reported_at, :thread_id, :cpu_usage, :idle, :other, :players_online)',
        );
        $this->statsInserter = new MultiRowInserter(
            $pdo,
            'cpu_stats',
            ['report_id', 'description_id', 'time_ms', 'calls', 'rel_usage', 'real_usage'],
            BatchInsert::maxRowsForColumns($pdo, 6),
        );
    }

    public function maxReportedAt(string $source): ?int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(reported_at) FROM cpu_reports WHERE source = :source');
        $stmt->execute(['source' => $source]);
        $val = $stmt->fetchColumn();

        return $val === false || $val === null ? null : (int) $val;
    }

    public function loadDedupKeysSince(string $source, int $sinceTimestamp): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.reported_at, s.description_id, s.time_ms, s.calls, s.rel_usage, s.real_usage
             FROM cpu_stats s
             INNER JOIN cpu_reports r ON r.id = s.report_id
             WHERE r.source = :source AND r.reported_at >= :since',
        );
        $stmt->execute(['source' => $source, 'since' => $sinceTimestamp]);

        $keys = [];
        while ($row = $stmt->fetch()) {
            $key = DedupKey::cpuStat(
                $source,
                (int) $row['reported_at'],
                (int) $row['description_id'],
                (int) $row['time_ms'],
                (int) $row['calls'],
                (string) $row['rel_usage'],
                (string) $row['real_usage'],
            );
            $keys[$key] = true;
        }

        return $keys;
    }

    public function insertReport(
        string $source,
        int $reportedAt,
        ?int $threadId,
        ?float $cpuUsage,
        ?float $idle,
        ?float $other,
        ?int $playersOnline,
    ): int {
        $this->insertReportStmt->execute([
            'source' => $source,
            'reported_at' => $reportedAt,
            'thread_id' => $threadId,
            'cpu_usage' => $cpuUsage,
            'idle' => $idle,
            'other' => $other,
            'players_online' => $playersOnline,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<array{report_id: int, description_id: int, time_ms: int, calls: int, rel_usage: float, real_usage: float}> $rows
     */
    public function insertStatsBatch(array $rows): void
    {
        $this->statsInserter->insert($rows);
    }

    public function countReports(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM cpu_reports')->fetchColumn();
    }

    public function countStats(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM cpu_stats')->fetchColumn();
    }
}
