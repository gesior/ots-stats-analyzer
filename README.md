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

## Import performance

The importer optimizes bulk loads:

- Secondary indexes are dropped during import and rebuilt once at the end (much faster inserts).
- One SQLite transaction per log file, with checkpoints every 32 MiB (resume after interrupt).
- Slow events use multi-row INSERTs (thousands of rows per SQL statement) with cached prepared statements.
- Description IDs are resolved in bulk per batch (not one DB round-trip per log line).

If you stop import with Ctrl+C, rerun the same command — it continues from the last checkpoint instead of re-reading the whole file.

### Tuning (environment variables)

| Variable | Default | Effect |
|----------|---------|--------|
| `OTS_BATCH_SIZE` | `20000` | Rows buffered in RAM before flush to SQLite |
| `OTS_INSERT_CHUNK_ROWS` | `250` | Rows per SQL INSERT (keep near 250; very large values slow PHP PDO) |
| `OTS_READ_MODE` | `stream` | `stream` (line-by-line), `chunk` (64 MiB fread blocks), or `file` (load whole file if ≤ `OTS_MAX_FILE_LOAD_BYTES`) |
| `OTS_READ_CHUNK_BYTES` | `67108864` | fread block size for `chunk` mode |
| `OTS_MAX_FILE_LOAD_BYTES` | `67108864` | Max file size for `file` mode |
| `OTS_MEMORY_LIMIT` | `32G` | PHP `memory_limit` |
| `OTS_SQLITE_AGGRESSIVE` | off | Set to `1` for larger SQLite cache/mmap and `journal_mode=MEMORY` during import |

Example for a machine with plenty of RAM (full import of large `*_slow.log` files):

```bash
set OTS_BATCH_SIZE=50000
set OTS_READ_MODE=chunk
set OTS_SQLITE_AGGRESSIVE=1
php bin/import.php import --data-dir=data
```

Progress reports **lines/s** and **rows inserted** — these are not the same as SQL statements. After tuning, expect roughly **2–4×** higher throughput on slow logs compared to the legacy 150-row inserts (e.g. ~30k → ~60–120k rows/s depending on disk and log content).

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
