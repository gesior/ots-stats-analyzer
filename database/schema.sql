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

CREATE INDEX IF NOT EXISTS idx_cpu_reports_source_time ON cpu_reports(source, reported_at);

CREATE TABLE IF NOT EXISTS cpu_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id INTEGER NOT NULL REFERENCES cpu_reports(id),
    description_id INTEGER NOT NULL REFERENCES descriptions(id),
    time_ms INTEGER NOT NULL,
    calls INTEGER NOT NULL,
    rel_usage REAL NOT NULL,
    real_usage REAL NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_cpu_stats_desc_time ON cpu_stats(description_id, report_id);
CREATE INDEX IF NOT EXISTS idx_cpu_stats_real_usage ON cpu_stats(real_usage);

CREATE TABLE IF NOT EXISTS slow_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    severity TEXT NOT NULL,
    occurred_at INTEGER NOT NULL,
    execution_ms INTEGER NOT NULL,
    description_id INTEGER NOT NULL REFERENCES descriptions(id),
    detail TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_slow_events_source_time ON slow_events(source, severity, occurred_at);
CREATE INDEX IF NOT EXISTS idx_slow_events_desc ON slow_events(description_id);

CREATE TABLE IF NOT EXISTS import_files (
    file_key TEXT PRIMARY KEY,
    path TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    file_mtime INTEGER NOT NULL,
    byte_offset INTEGER NOT NULL DEFAULT 0,
    max_occurred_at INTEGER,
    updated_at INTEGER NOT NULL
);
