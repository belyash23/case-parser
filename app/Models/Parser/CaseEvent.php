<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseEvent extends Model
{
    protected $fillable = [
        'case_instance_id',
        'event_date',
        'event_order',
        'event_type_raw',
        'event_type_normalized',
        'event_result_raw',
        'event_result_normalized',
        'source_url',
        'event_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
        ];
    }

    public function caseInstance(): BelongsTo
    {
        return $this->belongsTo(CaseInstance::class);
    }
}
