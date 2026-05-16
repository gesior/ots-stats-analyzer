<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use PDO;
use PDOStatement;

final class DescriptionRepository
{
    /** @var array<string, int> */
    private array $cache = [];

    private readonly PDOStatement $insertStmt;
    private readonly PDOStatement $selectStmt;
    private readonly MultiRowInserter $bulkInsert;

    public function __construct(
        private readonly PDO $pdo,
    ) {
        $this->insertStmt = $pdo->prepare(
            'INSERT OR IGNORE INTO descriptions (source, description) VALUES (:source, :description)',
        );
        $this->selectStmt = $pdo->prepare(
            'SELECT id FROM descriptions WHERE source = :source AND description = :description',
        );
        $this->bulkInsert = new MultiRowInserter(
            $pdo,
            'descriptions',
            ['source', 'description'],
            BatchInsert::maxRowsForColumns($pdo, 2),
            orIgnore: true,
        );
    }

    public function preloadSource(string $source): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, description FROM descriptions WHERE source = :source',
        );
        $stmt->execute(['source' => $source]);

        while ($row = $stmt->fetch()) {
            $this->cache[$source . "\0" . $row['description']] = (int) $row['id'];
        }
    }

    public function getOrCreate(string $source, string $description): int
    {
        $resolved = $this->resolveMany($source, [$description]);

        return $resolved[$description];
    }

    /**
     * @param list<string> $descriptions unique description strings
     * @return array<string, int>
     */
    public function resolveMany(string $source, array $descriptions): array
    {
        if ($descriptions === []) {
            return [];
        }

        $result = [];
        $missing = [];

        foreach ($descriptions as $description) {
            $cacheKey = $source . "\0" . $description;
            if (isset($this->cache[$cacheKey])) {
                $result[$description] = $this->cache[$cacheKey];
            } else {
                $missing[] = $description;
            }
        }

        if ($missing === []) {
            return $result;
        }

        $maxChunk = BatchInsert::maxRowsForColumns($this->pdo, 2);
        foreach (array_chunk($missing, $maxChunk) as $chunk) {
            $rows = [];
            foreach ($chunk as $description) {
                $rows[] = ['source' => $source, 'description' => $description];
            }
            $this->bulkInsert->insert($rows);
        }

        foreach (array_chunk($missing, $maxChunk) as $chunk) {
            $placeholders = [];
            $params = ['source' => $source];
            foreach ($chunk as $i => $description) {
                $key = "d{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $description;
            }

            $sql = 'SELECT id, description FROM descriptions WHERE source = :source AND description IN ('
                . implode(', ', $placeholders) . ')';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            while ($row = $stmt->fetch()) {
                $description = (string) $row['description'];
                $id = (int) $row['id'];
                $this->cache[$source . "\0" . $description] = $id;
                $result[$description] = $id;
            }
        }

        foreach ($missing as $description) {
            if (!isset($result[$description])) {
                $result[$description] = $this->getOrCreateFallback($source, $description);
            }
        }

        return $result;
    }

    private function getOrCreateFallback(string $source, string $description): int
    {
        $cacheKey = $source . "\0" . $description;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $this->insertStmt->execute(['source' => $source, 'description' => $description]);

        if ($this->insertStmt->rowCount() > 0) {
            $id = (int) $this->pdo->lastInsertId();
            $this->cache[$cacheKey] = $id;

            return $id;
        }

        $this->selectStmt->execute(['source' => $source, 'description' => $description]);
        $id = (int) $this->selectStmt->fetchColumn();
        $this->cache[$cacheKey] = $id;

        return $id;
    }
}
