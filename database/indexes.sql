CREATE INDEX IF NOT EXISTS idx_cpu_reports_source_time ON cpu_reports(source, reported_at);
CREATE INDEX IF NOT EXISTS idx_cpu_stats_desc_time ON cpu_stats(description_id, report_id);
CREATE INDEX IF NOT EXISTS idx_cpu_stats_real_usage ON cpu_stats(real_usage);
CREATE INDEX IF NOT EXISTS idx_slow_events_source_time ON slow_events(source, severity, occurred_at);
CREATE INDEX IF NOT EXISTS idx_slow_events_desc ON slow_events(description_id);
