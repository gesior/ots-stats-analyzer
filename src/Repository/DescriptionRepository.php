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

    public function __construct(
        private readonly PDO $pdo,
    ) {
        $this->insertStmt = $pdo->prepare(
            'INSERT OR IGNORE INTO descriptions (source, description) VALUES (:source, :description)',
        );
        $this->selectStmt = $pdo->prepare(
            'SELECT id FROM descriptions WHERE source = :source AND description = :description',
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
