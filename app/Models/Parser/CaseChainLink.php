<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;

class CaseChainLink extends Model
{
    protected $fillable = [
        'source_instance_id',
        'target_instance_id',
        'link_type',
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
}
