<?php

namespace App\Models\Parser;

use App\Enums\Parser\CrawlWorkStatus;
use App\Enums\Parser\CrawlWorkType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlWorkItem extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'priority' => 100,
        'attempts' => 0,
        'request_cost' => 0,
    ];

    protected $fillable = [
        'crawl_campaign_id',
        'court_id',
        'case_instance_id',
        'work_type',
        'status',
        'deduplication_key',
        'target_date',
        'priority',
        'available_at',
        'started_at',
        'finished_at',
        'attempts',
        'request_cost',
        'payload_json',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'work_type' => CrawlWorkType::class,
            'status' => CrawlWorkStatus::class,
            'target_date' => 'date',
            'priority' => 'integer',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'attempts' => 'integer',
            'request_cost' => 'integer',
            'payload_json' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CrawlCampaign::class, 'crawl_campaign_id');
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function caseInstance(): BelongsTo
    {
        return $this->belongsTo(CaseInstance::class);
    }
}
