<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AvailabilityIncident extends Model
{
    protected $attributes = [
        'status' => 'suspected',
        'failed_checks' => 0,
        'successful_checks' => 0,
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
        'notification_state' => 'not_notified',
    ];

    protected $fillable = [
        'court_id',
        'status',
        'opened_at',
        'confirmed_at',
        'resolved_at',
        'last_checked_at',
        'initial_outcome',
        'last_outcome',
        'failed_checks',
        'successful_checks',
        'consecutive_failures',
        'consecutive_successes',
        'worst_http_status',
        'notification_state',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(AvailabilityCheck::class);
    }
}
