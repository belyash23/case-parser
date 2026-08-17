<?php

namespace App\Http\Requests\Admin;

class UpdateParserSettingsRequest extends AdminRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'request_interval_ms' => ['required', 'integer', 'min:1000', 'max:300000'],
            'connect_timeout_seconds' => ['required', 'integer', 'min:1', 'max:120'],
            'directory_timeout_seconds' => ['required', 'integer', 'min:5', 'max:300'],
            'forbidden_cooldown_seconds' => ['required', 'integer', 'min:60', 'max:604800'],
            'rate_limit_cooldown_seconds' => ['required', 'integer', 'min:60', 'max:604800'],
            'timeout_circuit_threshold' => ['required', 'integer', 'min:1', 'max:20'],
            'timeout_cooldown_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
            'monitoring_enabled' => ['required', 'boolean'],
            'monitor_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'monitor_reuse_window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'monitor_timeout_seconds' => ['required', 'integer', 'min:5', 'max:300'],
            'monitor_connect_timeout_seconds' => ['required', 'integer', 'min:1', 'max:120'],
            'regular_scheduling_enabled' => ['required', 'boolean'],
            'regular_slice_seconds' => ['required', 'integer', 'min:10', 'max:110'],
            'capacity_utilization_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'base_budget_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'maximum_court_share_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'initial_maximum_case_attempts' => ['required', 'integer', 'min:1', 'max:20'],
            'regular_maximum_case_attempts' => ['required', 'integer', 'min:1', 'max:20'],
            'regular_failure_retry_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'regular_recheck_interval_days' => ['required', 'integer', 'min:1', 'max:365'],
            'regular_recheck_starvation_days' => ['required', 'integer', 'min:1', 'max:730'],
        ];
    }
}
