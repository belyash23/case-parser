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
        'case_uid',
        'external_case_id',
        'instance_level',
        'status_raw',
        'status_normalized',
        'result_raw',
        'result_normalized',
        'started_at',
        'completed_at',
        'category_raw',
        'category_normalized',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'date',
            'completed_at' => 'date',
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
}
