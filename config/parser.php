<?php

return [
    'version' => env('PARSER_VERSION', '0.1.0'),
    'user_agent' => env('PARSER_USER_AGENT', 'Mozilla/5.0 (compatible; CaseParser/0.1; +https://localhost)'),
    'verify_tls' => env('PARSER_VERIFY_TLS', false),
    'campaign' => [
        'stale_after_seconds' => (int) env('PARSER_CAMPAIGN_STALE_AFTER_SECONDS', 900),
    ],
    'initial' => [
        'maximum_case_attempts' => (int) env('PARSER_INITIAL_MAXIMUM_CASE_ATTEMPTS', 3),
    ],
    'regular' => [
        'scheduling_enabled' => (bool) env('PARSER_REGULAR_SCHEDULING_ENABLED', true),
        'scheduled_slice_seconds' => (int) env('PARSER_REGULAR_SCHEDULED_SLICE_SECONDS', 50),
        'capacity_utilization_percent' => (int) env('PARSER_REGULAR_CAPACITY_UTILIZATION_PERCENT', 80),
        'base_budget_percent' => (int) env('PARSER_REGULAR_BASE_BUDGET_PERCENT', 40),
        'maximum_court_share_percent' => (int) env('PARSER_REGULAR_MAXIMUM_COURT_SHARE_PERCENT', 10),
        'maximum_case_attempts' => (int) env('PARSER_REGULAR_MAXIMUM_CASE_ATTEMPTS', 3),
        'failure_retry_minutes' => (int) env('PARSER_REGULAR_FAILURE_RETRY_MINUTES', 60),
        'recheck_interval_days' => (int) env('PARSER_REGULAR_RECHECK_INTERVAL_DAYS', 30),
        'recheck_starvation_days' => (int) env('PARSER_REGULAR_RECHECK_STARVATION_DAYS', 45),
        'lane_sequence' => [
            'backlog_drain',
            'head_sync',
            'case_card',
            'head_sync',
            'recheck',
        ],
    ],
    'operations' => [
        'backup_enabled' => (bool) env('DATABASE_BACKUP_ENABLED', false),
        'backup_directory' => env('DATABASE_BACKUP_DIRECTORY', 'backups/database'),
        'backup_retention_days' => (int) env('DATABASE_BACKUP_RETENTION_DAYS', 7),
        'backup_timeout_seconds' => (int) env('DATABASE_BACKUP_TIMEOUT_SECONDS', 3600),
        'pruning_enabled' => (bool) env('PARSER_OPERATIONAL_PRUNING_ENABLED', true),
        'request_log_retention_days' => (int) env('PARSER_REQUEST_LOG_RETENTION_DAYS', 90),
        'parser_error_retention_days' => (int) env('PARSER_ERROR_RETENTION_DAYS', 180),
        'availability_check_retention_days' => (int) env('PARSER_AVAILABILITY_CHECK_RETENTION_DAYS', 365),
        'work_item_retention_days' => (int) env('PARSER_WORK_ITEM_RETENTION_DAYS', 180),
        'parser_run_retention_days' => (int) env('PARSER_RUN_RETENTION_DAYS', 365),
        'raw_page_file_retention_days' => (int) env('PARSER_RAW_PAGE_FILE_RETENTION_DAYS', 90),
        'raw_page_record_retention_days' => (int) env('PARSER_RAW_PAGE_RECORD_RETENTION_DAYS', 180),
    ],
    'sudrf' => [
        'minimum_request_interval_ms' => max(1000, (int) env('SUDRF_MIN_REQUEST_INTERVAL_MS', 10000)),
        'connect_timeout_seconds' => (int) env('SUDRF_CONNECT_TIMEOUT_SECONDS', 10),
        'directory_timeout_seconds' => (int) env('SUDRF_DIRECTORY_TIMEOUT_SECONDS', 45),
        'circuit' => [
            'forbidden_cooldown_seconds' => (int) env('SUDRF_FORBIDDEN_COOLDOWN_SECONDS', 21600),
            'rate_limit_cooldown_seconds' => (int) env('SUDRF_RATE_LIMIT_COOLDOWN_SECONDS', 21600),
            'timeout_threshold' => (int) env('SUDRF_TIMEOUT_CIRCUIT_THRESHOLD', 3),
            'timeout_cooldown_seconds' => (int) env('SUDRF_TIMEOUT_COOLDOWN_SECONDS', 3600),
        ],
    ],
];
