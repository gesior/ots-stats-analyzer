<?php

declare(strict_types=1);

use OtsStats\Repository\Database;
use OtsStats\Repository\SlowReadRepository;
use OtsStats\Repository\StatsReadRepository;
use OtsStats\Util\TimeRange;

require __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

$root = __DIR__;
$config = require $root . '/config/web.php';

try {
    $dbPath = resolveDbPath($root, (string) $config['db_path']);
    $database = new Database(
        $dbPath,
        $root . '/database/schema.sql',
        $root . '/database/indexes.sql',
    );
    $pdo = $database->pdo();
    $pdo->exec('PRAGMA query_only=ON');

    $timeRange = new TimeRange(
        $config['ranges'],
        $config['bucket_seconds'],
        (int) $config['max_chart_points'],
    );

    $repository = new StatsReadRepository(
        $pdo,
        $config['sources'],
        $timeRange,
        (int) $config['top_functions_limit'],
    );

    $slowRepository = new SlowReadRepository(
        $pdo,
        $config['sources'],
        $timeRange,
        (int) $config['top_functions_limit'],
    );

    $action = $_GET['action'] ?? '';
    $payload = match ($action) {
        'meta' => $repository->meta(),
        'overview' => handleOverview($repository, $config, $_GET),
        'top-functions' => handleTopFunctions($repository, $config, $_GET),
        'function-series' => handleFunctionSeries($repository, $_GET),
        'slow-meta' => $slowRepository->meta(),
        'slow-overview' => handleSlowOverview($slowRepository, $config, $_GET),
        'slow-top-functions' => handleSlowTopFunctions($slowRepository, $config, $_GET),
        'slow-function-series' => handleSlowFunctionSeries($slowRepository, $_GET),
        default => throw new InvalidArgumentException('Unknown or missing action'),
    };

    echo json_encode($payload, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    respondError(400, $e->getMessage());
} catch (Throwable $e) {
    respondError(500, 'Internal server error');
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function handleOverview(StatsReadRepository $repository, array $config, array $query): array
{
    $source = (string) ($query['source'] ?? $config['default_source']);
    $range = (string) ($query['range'] ?? $config['default_range']);
    $end = requireEndTimestamp($query, $repository);

    return $repository->overview($source, $end, $range);
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function handleTopFunctions(StatsReadRepository $repository, array $config, array $query): array
{
    $source = (string) ($query['source'] ?? $config['default_source']);
    $range = (string) ($query['range'] ?? $config['default_range']);
    $sort = (string) ($query['sort'] ?? 'total');
    $limit = isset($query['limit']) ? (int) $query['limit'] : (int) $config['top_functions_limit'];
    $end = requireEndTimestamp($query, $repository);

    return $repository->topFunctions($source, $end, $range, $sort, $limit);
}

/**
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function handleFunctionSeries(StatsReadRepository $repository, array $query): array
{
    if (!isset($query['description_id'])) {
        throw new InvalidArgumentException('Missing description_id');
    }

    $descriptionId = (int) $query['description_id'];
    if ($descriptionId <= 0) {
        throw new InvalidArgumentException('Invalid description_id');
    }

    $range = (string) ($query['range'] ?? 'day');
    $end = requireEndTimestamp($query, $repository);

    return $repository->functionSeries($descriptionId, $end, $range);
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function handleSlowOverview(SlowReadRepository $repository, array $config, array $query): array
{
    $source = (string) ($query['source'] ?? $config['default_source']);
    $range = (string) ($query['range'] ?? $config['default_range']);
    $end = requireSlowEndTimestamp($query, $repository);

    return $repository->overview($source, $end, $range);
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function handleSlowTopFunctions(SlowReadRepository $repository, array $config, array $query): array
{
    $source = (string) ($query['source'] ?? $config['default_source']);
    $range = (string) ($query['range'] ?? $config['default_range']);
    $sort = (string) ($query['sort'] ?? 'count');
    $limit = isset($query['limit']) ? (int) $query['limit'] : (int) $config['top_functions_limit'];
    $end = requireSlowEndTimestamp($query, $repository);

    return $repository->topFunctions($source, $end, $range, $sort, $limit);
}

/**
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function handleSlowFunctionSeries(SlowReadRepository $repository, array $query): array
{
    if (!isset($query['description_id'])) {
        throw new InvalidArgumentException('Missing description_id');
    }

    $descriptionId = (int) $query['description_id'];
    if ($descriptionId <= 0) {
        throw new InvalidArgumentException('Invalid description_id');
    }

    $range = (string) ($query['range'] ?? 'day');
    $end = requireSlowEndTimestamp($query, $repository);

    return $repository->functionSeries($descriptionId, $end, $range);
}

/**
 * @param array<string, mixed> $query
 */
function requireEndTimestamp(array $query, StatsReadRepository $repository): int
{
    if (isset($query['end'])) {
        $end = (int) $query['end'];
        if ($end <= 0) {
            throw new InvalidArgumentException('Invalid end timestamp');
        }

        return $end;
    }

    $meta = $repository->meta();
    $defaultEnd = $meta['default_end'] ?? null;
    if ($defaultEnd === null) {
        throw new InvalidArgumentException('No data in database');
    }

    return (int) $defaultEnd;
}

/**
 * @param array<string, mixed> $query
 */
function requireSlowEndTimestamp(array $query, SlowReadRepository $repository): int
{
    if (isset($query['end'])) {
        $end = (int) $query['end'];
        if ($end <= 0) {
            throw new InvalidArgumentException('Invalid end timestamp');
        }

        return $end;
    }

    $meta = $repository->meta();
    $defaultEnd = $meta['default_end'] ?? null;
    if ($defaultEnd === null) {
        throw new InvalidArgumentException('No slow event data in database');
    }

    return (int) $defaultEnd;
}

function resolveDbPath(string $root, string $dbPath): string
{
    if ($dbPath === '' || $dbPath[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $dbPath) === 1) {
        return $dbPath;
    }

    return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dbPath);
}

function respondError(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    exit;
}
