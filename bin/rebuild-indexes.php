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

$pdo->exec('DROP INDEX IF EXISTS idx_cpu_reports_source_time');
$pdo->exec('DROP INDEX IF EXISTS idx_cpu_reports_overview');
$pdo->exec('DROP INDEX IF EXISTS idx_cpu_stats_report');
$pdo->exec('DROP INDEX IF EXISTS idx_cpu_stats_desc_time');
$pdo->exec('DROP INDEX IF EXISTS idx_cpu_stats_real_usage');
$pdo->exec('DROP INDEX IF EXISTS idx_slow_events_source_time');
$pdo->exec('DROP INDEX IF EXISTS idx_slow_events_desc');

echo "  Old indexes dropped.\n";

$pdo->exec((string) file_get_contents($root . '/database/indexes.sql'));
echo "  New indexes created.\n";

$pdo->exec('DELETE FROM cpu_overview_agg');
$pdo->exec(
    'INSERT INTO cpu_overview_agg (source, bucket_time, avg_cpu_usage, avg_players_online, sample_count)
     SELECT source,
            (reported_at / 30) * 30 AS bucket_time,
            AVG(cpu_usage),
            AVG(players_online),
            COUNT(*)
     FROM cpu_reports
     GROUP BY source, bucket_time',
);

$aggCount = (int) $pdo->query('SELECT COUNT(*) FROM cpu_overview_agg')->fetchColumn();
echo "  Aggregation table rebuilt ({$aggCount} rows).\n";

$pdo->exec('PRAGMA optimize');
echo "Done.\n";
