<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use PDO;

final class BatchInsert
{
    /**
     * @template T
     * @param list<T> $rows
     * @param callable(list<T>): void $insertChunk
     */
    public static function chunked(array $rows, int $maxRowsPerStatement, callable $insertChunk): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, max(1, $maxRowsPerStatement)) as $chunk) {
            $insertChunk($chunk);
        }
    }

    public static function maxRowsForColumns(PDO $pdo, int $columnsPerRow): int
    {
        return SqliteLimits::maxRowsPerStatement($pdo, $columnsPerRow);
    }
}
