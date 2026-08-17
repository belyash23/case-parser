<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $attributes = [
        'source_type' => 'sudrf',
        'is_enabled' => true,
        'sync_status' => 'pending',
    ];

    protected $fillable = [
        'source_type',
        'sudrf_region_id',
        'name',
        'is_enabled',
        'sync_status',
        'last_synced_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function courts(): HasMany
    {
        return $this->hasMany(Court::class);
    }
}
