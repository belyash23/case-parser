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
        'normalized_case_number_aliases_json',
        'primary_court_id',
        'category_raw',
        'category_normalized',
        'category_path_json',
        'category_level_1',
        'category_level_2',
        'category_level_3',
        'category_level_4',
        'category_leaf',
        'case_type',
        'dispute_status',
        'final_disposition_type',
        'chain_status',
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
            'normalized_case_number_aliases_json' => 'array',
            'category_path_json' => 'array',
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
