<?php

namespace App\Models\Parser;

use App\Enums\Parser\SourceCircuitStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceRuntimeState extends Model
{
    protected $attributes = [
        'source_type' => 'sudrf',
        'circuit_status' => 'closed',
        'consecutive_timeouts' => 0,
    ];

    protected $fillable = [
        'source_type',
        'active_crawl_campaign_id',
        'circuit_status',
        'last_request_started_at',
        'next_request_at',
        'circuit_opened_at',
        'cooldown_until',
        'circuit_reason',
        'consecutive_timeouts',
        'last_failure_at',
        'last_success_at',
    ];

    protected function casts(): array
    {
        return [
            'circuit_status' => SourceCircuitStatus::class,
            'last_request_started_at' => 'datetime',
            'next_request_at' => 'datetime',
            'circuit_opened_at' => 'datetime',
            'cooldown_until' => 'datetime',
            'consecutive_timeouts' => 'integer',
            'last_failure_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function activeCampaign(): BelongsTo
    {
        return $this->belongsTo(CrawlCampaign::class, 'active_crawl_campaign_id');
    }
}
