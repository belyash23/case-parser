<?php

return [
    'sudrf' => [
        'enabled' => env('SUDRF_MONITORING_ENABLED', false),
        'reuse_parser_window_minutes' => (int) env('SUDRF_MONITORING_REUSE_WINDOW_MINUTES', 10),
        'timeout_seconds' => (int) env('SUDRF_MONITORING_TIMEOUT_SECONDS', 45),
        'connect_timeout_seconds' => (int) env('SUDRF_MONITORING_CONNECT_TIMEOUT_SECONDS', 10),
        'failure_threshold' => (int) env('SUDRF_MONITORING_FAILURE_THRESHOLD', 2),
        'recovery_threshold' => (int) env('SUDRF_MONITORING_RECOVERY_THRESHOLD', 2),
        'probe_node' => env('SUDRF_MONITORING_PROBE_NODE', gethostname() ?: 'unknown'),
    ],
];
