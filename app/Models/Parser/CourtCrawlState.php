<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourtCrawlState extends Model
{
    protected $attributes = [
        'has_backlog' => false,
    ];

    protected $fillable = [
        'court_id',
        'initial_cursor_date',
        'head_cursor_date',
        'backlog_cursor_date',
        'has_backlog',
        'last_attempted_at',
        'last_successful_at',
        'next_eligible_at',
        'stats_json',
    ];

    protected function casts(): array
    {
        return [
            'initial_cursor_date' => 'date',
            'head_cursor_date' => 'date',
            'backlog_cursor_date' => 'date',
            'has_backlog' => 'boolean',
            'last_attempted_at' => 'datetime',
            'last_successful_at' => 'datetime',
            'next_eligible_at' => 'datetime',
            'stats_json' => 'array',
        ];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }
}
