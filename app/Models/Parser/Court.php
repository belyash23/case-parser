<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Court extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'region',
        'city',
        'court_level',
        'court_type',
        'source_type',
        'base_url',
        'layout_type',
        'status',
        'is_enabled',
        'min_request_interval_ms',
        'max_parallel_requests',
        'timeout_ms',
        'retry_count',
        'backoff_multiplier',
        'crawl_priority',
        'last_checked_at',
        'last_successful_crawl_at',
    ];

    protected $attributes = [
        'source_type' => 'sudrf',
        'status' => 'active',
        'is_enabled' => true,
        'min_request_interval_ms' => 3000,
        'max_parallel_requests' => 1,
        'timeout_ms' => 30000,
        'retry_count' => 2,
        'backoff_multiplier' => 1.8,
        'crawl_priority' => 100,
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'backoff_multiplier' => 'decimal:2',
            'last_checked_at' => 'datetime',
            'last_successful_crawl_at' => 'datetime',
        ];
    }

    public function caseInstances(): HasMany
    {
        return $this->hasMany(CaseInstance::class);
    }
}
