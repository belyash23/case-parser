<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourtCase extends Model
{
    protected $table = 'cases';

    protected $fillable = [
        'normalized_case_number',
        'primary_court_id',
        'category_raw',
        'category_normalized',
        'proceeding_type',
        'received_date',
        'final_observed_date',
        'observation_window_from',
        'observation_window_to',
        'is_training_candidate',
        'discovered_via',
        'has_appeal',
        'has_cassation',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'final_observed_date' => 'date',
            'observation_window_from' => 'date',
            'observation_window_to' => 'date',
            'is_training_candidate' => 'boolean',
            'has_appeal' => 'boolean',
            'has_cassation' => 'boolean',
        ];
    }

    public function primaryCourt(): BelongsTo
    {
        return $this->belongsTo(Court::class, 'primary_court_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(CaseInstance::class, 'case_id');
    }
}
