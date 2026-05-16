<?php

declare(strict_types=1);

namespace OtsStats\Repository;

use PDO;

final class SqliteLimits
{
    private const DEFAULT_MAX_VARIABLES = 999;
    private const MODERN_MAX_VARIABLES = 32766;

    /** Sweet spot for PHP PDO + SQLite (very large multi-row binds are slow in PHP). */
    private const DEFAULT_INSERT_CHUNK_ROWS = 250;

    private static ?int $insertChunkCap = null;

    public static function setInsertChunkCap(int $cap): void
    {
        self::$insertChunkCap = max(1, $cap);
    }

    public static function maxRowsPerStatement(PDO $pdo, int $columnsPerRow): int
    {
        $configuredCap = self::$insertChunkCap
            ?? (int) (getenv('OTS_INSERT_CHUNK_ROWS') ?: self::DEFAULT_INSERT_CHUNK_ROWS);
        $cap = max(1, $configuredCap);
        $maxVariables = self::maxBindParameters($pdo);

        return min($cap, intdiv($maxVariables, max(1, $columnsPerRow)));
    }

    public static function maxBindParameters(PDO $pdo): int
    {
        $options = $pdo->query('PRAGMA compile_options')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($options as $option) {
            if (is_string($option) && str_starts_with($option, 'MAX_VARIABLE_NUMBER=')) {
                return (int) substr($option, 20);
            }
        }

        if (PHP_VERSION_ID >= 80100) {
            return self::MODERN_MAX_VARIABLES;
        }

        return self::DEFAULT_MAX_VARIABLES;
    }
}
