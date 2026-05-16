# OTS Stats Analyzer

CLI tool to import [OTS Statistics](https://otland.net/threads/how-to-read-ots-statistics-logs.283722/) logs from a Tibia OT server into SQLite for analysis.

## Requirements

- PHP 8.1+
- Composer

## Setup

```bash
composer install
```

Place log files in `data/` (flat layout):

- `dispatcher.log`, `dispatcher_slow.log`, `dispatcher_very_slow.log`
- `lua.log`, `lua_slow.log`, `lua_very_slow.log`
- `sql.log`, `sql_slow.log`, `sql_very_slow.log`
- `special.log`, `special_slow.log`, `special_very_slow.log`

## Import

First run (full import, may take a long time for multi-GB logs):

```bash
php bin/import.php import --data-dir=data --db=var/ots-stats.sqlite
```

Incremental run (only new appended lines; deduplicates last 7 days via RAM):

```bash
php bin/import.php import --data-dir=data --db=var/ots-stats.sqlite
```

Progress is printed to stderr every 3 seconds (bytes, speed, ETA per file and total):

```bash
php bin/import.php import --progress-interval=3 2>import-progress.log
```

Options:

- `--data-dir` — log directory (default: `data`)
- `--db` — SQLite path (default: `var/ots-stats.sqlite`)
- `--dedup-days=7` — load dedup keys from DB for this many days
- `--memory-limit=32G` — PHP memory limit
- `--progress-interval=0` — disable progress output

## Status

```bash
php bin/import.php status
```

## Tests

```bash
vendor/bin/phpunit
```

## Database

SQLite schema in `database/schema.sql`. Main tables:

- `cpu_reports` / `cpu_stats` — 30-second CPU usage reports from `*.log`
- `slow_events` — single slow executions from `*_slow.log` and `*_very_slow.log`
- `descriptions` — normalized function/SQL/script names
- `import_files` — per-file byte offset for resume
