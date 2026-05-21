<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use OtsStats\Repository\Database;

$root = dirname(__DIR__);
$config = require $root . '/config/web.php';

$dbPath = $config['db_path'];
if ($dbPath === '' || $dbPath[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $dbPath) === 1) {
    // absolute path
} else {
    $dbPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dbPath);
}

if (!file_exists($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}\n");
    exit(1);
}

echo "Database: {$dbPath}\n";
echo "Rebuilding indexes and aggregation table...\n";

$database = new Database($dbPath, $root . '/database/schema.sql', $root . '/database/indexes.sql');
$pdo = $database->pdo();

foreach ([
    'idx_cpu_reports_source_time',
    'idx_cpu_reports_overview',
    'idx_cpu_stats_report',
    'idx_cpu_stats_desc_time',
    'idx_cpu_stats_real_usage',
    'idx_slow_events_source_time',
    'idx_slow_events_source_occurred',
    'idx_slow_events_desc',
    'idx_slow_events_desc_time',
] as $name) {
    $pdo->exec("DROP INDEX IF EXISTS {$name}");
}

echo "  Old indexes dropped.\n";

$database->rebuildSecondaryIndexesAndAgg(
    static function (string $message): void {
        echo '  ', $message, "\n";
    },
);

$pdo->exec('PRAGMA optimize');
echo "Done.\n";
