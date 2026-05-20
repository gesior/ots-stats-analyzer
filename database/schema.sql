CREATE TABLE IF NOT EXISTS descriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    description TEXT NOT NULL,
    UNIQUE(source, description)
);

CREATE TABLE IF NOT EXISTS cpu_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    reported_at INTEGER NOT NULL,
    thread_id INTEGER,
    cpu_usage REAL,
    idle REAL,
    other REAL,
    players_online INTEGER
);

CREATE TABLE IF NOT EXISTS cpu_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id INTEGER NOT NULL REFERENCES cpu_reports(id),
    description_id INTEGER NOT NULL REFERENCES descriptions(id),
    time_ms INTEGER NOT NULL,
    calls INTEGER NOT NULL,
    rel_usage REAL NOT NULL,
    real_usage REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS slow_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    severity TEXT NOT NULL,
    occurred_at INTEGER NOT NULL,
    execution_ms INTEGER NOT NULL,
    description_id INTEGER NOT NULL REFERENCES descriptions(id),
    detail TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS cpu_overview_agg (
    source TEXT NOT NULL,
    bucket_time INTEGER NOT NULL,
    avg_cpu_usage REAL,
    avg_players_online REAL,
    sample_count INTEGER NOT NULL,
    PRIMARY KEY(source, bucket_time)
);

CREATE TABLE IF NOT EXISTS import_files (
    file_key TEXT PRIMARY KEY,
    path TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    file_mtime INTEGER NOT NULL,
    byte_offset INTEGER NOT NULL DEFAULT 0,
    max_occurred_at INTEGER,
    updated_at INTEGER NOT NULL
);
