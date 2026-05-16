<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use OtsStats\Util\DedupKey;
use PDO;

final class SlowEventRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
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
            'SELECT occurred_at, execution_ms, description_id, detail
             FROM slow_events
             WHERE source = :source AND severity = :severity AND occurred_at >= :since',
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
                (int) $row['description_id'],
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
        BatchInsert::chunked($rows, function (array $chunk): void {
            $this->insertChunk($chunk);
        });
    }

    /**
     * @param list<array{source: string, severity: string, occurred_at: int, execution_ms: int, description_id: int, detail: string}> $rows
     */
    private function insertChunk(array $rows): void
    {
        $placeholders = [];
        $params = [];
        foreach ($rows as $i => $row) {
            $placeholders[] = "(:source{$i}, :severity{$i}, :occurred_at{$i}, :execution_ms{$i}, :description_id{$i}, :detail{$i})";
            $params["source{$i}"] = $row['source'];
            $params["severity{$i}"] = $row['severity'];
            $params["occurred_at{$i}"] = $row['occurred_at'];
            $params["execution_ms{$i}"] = $row['execution_ms'];
            $params["description_id{$i}"] = $row['description_id'];
            $params["detail{$i}"] = $row['detail'];
        }

        $sql = 'INSERT INTO slow_events (source, severity, occurred_at, execution_ms, description_id, detail) VALUES '
            . implode(', ', $placeholders);
        $this->pdo->prepare($sql)->execute($params);
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM slow_events')->fetchColumn();
    }
}
