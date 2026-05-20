<?php

declare(strict_types=1);

return [
    'db_path' => getenv('OTS_DB_PATH') ?: 'var/ots-stats.sqlite',
    'sources' => ['dispatcher', 'lua', 'sql', 'special'],
    'ranges' => [
        'hour' => 3600,
        'day' => 86400,
        '7d' => 604800,
    ],
    'bucket_seconds' => [
        'hour' => 30,
        'day' => 60,
        '7d' => 300,
    ],
    'max_chart_points' => 1500,
    'default_range' => 'day',
    'default_source' => 'dispatcher',
    'top_functions_limit' => 50,
];
