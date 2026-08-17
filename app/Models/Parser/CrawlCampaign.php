<?php

namespace App\Models\Parser;

use App\Enums\Parser\CrawlCampaignMode;
use App\Enums\Parser\CrawlCampaignStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CrawlCampaign extends Model
{
    protected $attributes = [
        'source_type' => 'sudrf',
        'status' => 'pending',
        'requests_used' => 0,
    ];

    protected $fillable = [
        'source_type',
        'mode',
        'status',
        'window_from',
        'window_to',
        'settings_json',
        'request_budget',
        'requests_used',
        'started_at',
        'paused_at',
        'finished_at',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => CrawlCampaignMode::class,
            'status' => CrawlCampaignStatus::class,
            'window_from' => 'date',
            'window_to' => 'date',
            'settings_json' => 'array',
            'request_budget' => 'integer',
            'requests_used' => 'integer',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(CrawlWorkItem::class);
    }

    public function parserRuns(): HasMany
    {
        return $this->hasMany(ParserRun::class);
    }

    public function activeSourceState(): HasOne
    {
        return $this->hasOne(SourceRuntimeState::class, 'active_crawl_campaign_id');
    }
}
