<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use PDO;

final class DescriptionRepository
{
    /** @var array<string, int> */
    private array $cache = [];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function getOrCreate(string $source, string $description): int
    {
        $cacheKey = $source . "\0" . $description;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO descriptions (source, description) VALUES (:source, :description)',
        );
        $stmt->execute(['source' => $source, 'description' => $description]);

        $select = $this->pdo->prepare(
            'SELECT id FROM descriptions WHERE source = :source AND description = :description',
        );
        $select->execute(['source' => $source, 'description' => $description]);
        $id = (int) $select->fetchColumn();

        $this->cache[$cacheKey] = $id;

        return $id;
    }

}
