<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;

class ParserSetting extends Model
{
    protected $attributes = [
        'request_interval_ms' => 10000,
        'connect_timeout_seconds' => 10,
        'directory_timeout_seconds' => 45,
        'forbidden_cooldown_seconds' => 21600,
        'rate_limit_cooldown_seconds' => 21600,
        'timeout_circuit_threshold' => 3,
        'timeout_cooldown_seconds' => 3600,
        'monitoring_enabled' => false,
        'monitor_interval_minutes' => 10,
        'monitor_reuse_window_minutes' => 10,
        'monitor_timeout_seconds' => 45,
        'monitor_connect_timeout_seconds' => 10,
        'regular_scheduling_enabled' => true,
        'regular_slice_seconds' => 50,
        'capacity_utilization_percent' => 80,
        'base_budget_percent' => 40,
        'maximum_court_share_percent' => 10,
        'initial_maximum_case_attempts' => 3,
        'regular_maximum_case_attempts' => 3,
        'regular_failure_retry_minutes' => 60,
        'regular_recheck_interval_days' => 30,
        'regular_recheck_starvation_days' => 45,
    ];

    protected $fillable = [
        'request_interval_ms', 'connect_timeout_seconds', 'directory_timeout_seconds',
        'forbidden_cooldown_seconds', 'rate_limit_cooldown_seconds', 'timeout_circuit_threshold',
        'timeout_cooldown_seconds', 'monitoring_enabled', 'monitor_interval_minutes',
        'monitor_reuse_window_minutes', 'monitor_timeout_seconds', 'monitor_connect_timeout_seconds',
        'regular_scheduling_enabled', 'regular_slice_seconds', 'capacity_utilization_percent',
        'base_budget_percent', 'maximum_court_share_percent', 'initial_maximum_case_attempts',
        'regular_maximum_case_attempts', 'regular_failure_retry_minutes',
        'regular_recheck_interval_days', 'regular_recheck_starvation_days',
    ];

    protected function casts(): array
    {
        return [
            'monitoring_enabled' => 'boolean',
            'regular_scheduling_enabled' => 'boolean',
            'request_interval_ms' => 'integer',
            'connect_timeout_seconds' => 'integer',
            'directory_timeout_seconds' => 'integer',
            'forbidden_cooldown_seconds' => 'integer',
            'rate_limit_cooldown_seconds' => 'integer',
            'timeout_circuit_threshold' => 'integer',
            'timeout_cooldown_seconds' => 'integer',
            'monitor_interval_minutes' => 'integer',
            'monitor_reuse_window_minutes' => 'integer',
            'monitor_timeout_seconds' => 'integer',
            'monitor_connect_timeout_seconds' => 'integer',
            'regular_slice_seconds' => 'integer',
            'capacity_utilization_percent' => 'integer',
            'base_budget_percent' => 'integer',
            'maximum_court_share_percent' => 'integer',
            'initial_maximum_case_attempts' => 'integer',
            'regular_maximum_case_attempts' => 'integer',
            'regular_failure_retry_minutes' => 'integer',
            'regular_recheck_interval_days' => 'integer',
            'regular_recheck_starvation_days' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }
}
