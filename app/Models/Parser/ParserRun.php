<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParserRun extends Model
{
    protected $fillable = [
        'run_type',
        'status',
        'started_at',
        'finished_at',
        'parser_version',
        'settings_json',
        'total_requests',
        'successful_requests',
        'failed_requests',
        'calendar_days_count',
        'calendar_case_links_count',
        'new_cases_count',
        'updated_cases_count',
        'new_events_count',
        'out_of_window_cases_count',
        'training_candidate_cases_count',
        'error_count',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'settings_json' => 'array',
        ];
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(RequestLog::class);
    }

    public function markCompleted(): void
    {
        $this->forceFill([
            'status' => 'completed',
            'finished_at' => now(),
        ])->save();
    }

    public function markFailed(): void
    {
        $this->forceFill([
            'status' => 'failed',
            'finished_at' => now(),
        ])->save();
    }
}
