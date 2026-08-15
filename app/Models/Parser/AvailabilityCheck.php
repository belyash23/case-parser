<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityCheck extends Model
{
    protected $attributes = [
        'endpoint_type' => 'case_list',
    ];

    protected $fillable = [
        'court_id',
        'request_log_id',
        'availability_incident_id',
        'source',
        'endpoint_type',
        'url',
        'checked_at',
        'outcome',
        'http_status',
        'duration_ms',
        'response_size_bytes',
        'retry_after_seconds',
        'error_type',
        'error_message',
        'response_hash',
        'probe_node',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function requestLog(): BelongsTo
    {
        return $this->belongsTo(RequestLog::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(AvailabilityIncident::class, 'availability_incident_id');
    }

    public function isSuccessful(): bool
    {
        return $this->outcome === 'success';
    }
}
