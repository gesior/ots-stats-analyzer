<?php

declare(strict_types=1);

namespace OtsStats\Repository;

/**
 * SQLite limits bound parameters per statement (often 999).
 * 6 columns per row → max ~150 rows per multi-value INSERT.
 */
final class BatchInsert
{
    public const MAX_ROWS_PER_STATEMENT = 150;

    /**
     * @template T
     * @param list<T> $rows
     * @param callable(list<T>): void $insertChunk
     */
    public static function chunked(array $rows, callable $insertChunk): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, self::MAX_ROWS_PER_STATEMENT) as $chunk) {
            $insertChunk($chunk);
        }
    }
}
