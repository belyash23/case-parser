<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseChainLink extends Model
{
    protected $fillable = [
        'source_instance_id',
        'target_instance_id',
        'link_type',
        'status',
        'matched_by',
        'confidence',
        'evidence_json',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'evidence_json' => 'array',
        ];
    }

    public function sourceInstance(): BelongsTo
    {
        return $this->belongsTo(CaseInstance::class, 'source_instance_id');
    }

    public function targetInstance(): BelongsTo
    {
        return $this->belongsTo(CaseInstance::class, 'target_instance_id');
    }
}
