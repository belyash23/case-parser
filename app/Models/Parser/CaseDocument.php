<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseDocument extends Model
{
    protected $fillable = [
        'case_instance_id',
        'document_type_raw',
        'document_type_normalized',
        'document_number',
        'document_date',
        'document_kind',
        'source_url',
        'document_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
        ];
    }

    public function caseInstance(): BelongsTo
    {
        return $this->belongsTo(CaseInstance::class);
    }
}
