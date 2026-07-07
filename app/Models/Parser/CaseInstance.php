<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseInstance extends Model
{
    protected $fillable = [
        'case_id',
        'court_id',
        'raw_page_id',
        'source_type',
        'source_url',
        'source_url_hash',
        'external_case_number',
        'normalized_case_number_aliases_json',
        'case_uid',
        'external_case_id',
        'source_case_type_id',
        'case_type',
        'instance_level',
        'court_instance_status_raw',
        'court_instance_status_normalized',
        'dispute_status_normalized',
        'disposition_type',
        'result_raw',
        'result_normalized',
        'started_at',
        'completed_at',
        'category_raw',
        'category_normalized',
        'category_path_json',
        'category_level_1',
        'category_level_2',
        'category_level_3',
        'category_level_4',
        'category_leaf',
    ];

    protected function casts(): array
    {
        return [
            'normalized_case_number_aliases_json' => 'array',
            'started_at' => 'date',
            'completed_at' => 'date',
            'category_path_json' => 'array',
        ];
    }

    public function courtCase(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function rawPage(): BelongsTo
    {
        return $this->belongsTo(RawPage::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CaseDocument::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(CaseParty::class);
    }

    public function outgoingChainLinks(): HasMany
    {
        return $this->hasMany(CaseChainLink::class, 'source_instance_id');
    }

    public function incomingChainLinks(): HasMany
    {
        return $this->hasMany(CaseChainLink::class, 'target_instance_id');
    }
}
