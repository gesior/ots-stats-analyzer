<?php

declare(strict_types=1);

return [
    'data_dir' => getenv('OTS_DATA_DIR') ?: 'data',
    'db_path' => getenv('OTS_DB_PATH') ?: 'var/ots-stats.sqlite',
    'memory_limit' => getenv('OTS_MEMORY_LIMIT') ?: '32G',
    'batch_size' => (int) (getenv('OTS_BATCH_SIZE') ?: 20000),
    'insert_chunk_rows' => (int) (getenv('OTS_INSERT_CHUNK_ROWS') ?: 250),
    'read_mode' => getenv('OTS_READ_MODE') ?: 'stream',
    'read_chunk_bytes' => (int) (getenv('OTS_READ_CHUNK_BYTES') ?: 67_108_864),
    'max_file_load_bytes' => (int) (getenv('OTS_MAX_FILE_LOAD_BYTES') ?: 67_108_864),
    'checkpoint_bytes' => (int) (getenv('OTS_CHECKPOINT_BYTES') ?: 33_554_432),
    'dedup_days' => (int) (getenv('OTS_DEDUP_DAYS') ?: 7),
    'progress_interval_seconds' => (float) (getenv('OTS_PROGRESS_INTERVAL') ?: 3),
    'sources' => ['dispatcher', 'lua', 'sql', 'special'],
    'file_types' => ['cpu', 'slow', 'very_slow'],
];
