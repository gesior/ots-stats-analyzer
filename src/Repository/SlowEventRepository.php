<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use OtsStats\Util\DedupKey;
use PDO;

final class SlowEventRepository
{
    private readonly MultiRowInserter $inserter;

    public function __construct(
        private readonly PDO $pdo,
    ) {
        $this->inserter = new MultiRowInserter(
            $pdo,
            'slow_events',
            ['source', 'severity', 'occurred_at', 'execution_ms', 'description_id', 'detail'],
            BatchInsert::maxRowsForColumns($pdo, 6),
        );
    }

    public function maxOccurredAt(string $source, string $severity): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(occurred_at) FROM slow_events WHERE source = :source AND severity = :severity',
        );
        $stmt->execute(['source' => $source, 'severity' => $severity]);
        $val = $stmt->fetchColumn();

        return $val === false || $val === null ? null : (int) $val;
    }

    public function loadDedupKeysSince(string $source, string $severity, int $sinceTimestamp): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT se.occurred_at, se.execution_ms, d.description, se.detail
             FROM slow_events se
             INNER JOIN descriptions d ON d.id = se.description_id
             WHERE se.source = :source AND se.severity = :severity AND se.occurred_at >= :since',
        );
        $stmt->execute([
            'source' => $source,
            'severity' => $severity,
            'since' => $sinceTimestamp,
        ]);

        $keys = [];
        while ($row = $stmt->fetch()) {
            $key = DedupKey::slow(
                $source,
                $severity,
                (int) $row['occurred_at'],
                (int) $row['execution_ms'],
                (string) $row['description'],
                (string) $row['detail'],
            );
            $keys[$key] = true;
        }

        return $keys;
    }

    /**
     * @param list<array{source: string, severity: string, occurred_at: int, execution_ms: int, description_id: int, detail: string}> $rows
     */
    public function insertBatch(array $rows): void
    {
        $this->inserter->insert($rows);
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM slow_events')->fetchColumn();
    }
}
