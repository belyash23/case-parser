<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseParty extends Model
{
    protected $fillable = [
        'case_instance_id',
        'role',
        'role_group',
        'party_type',
        'is_hidden',
        'source_role',
        'confidence',
    ];


    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
        ];
    }
    public function caseInstance(): BelongsTo
    {
        return $this->belongsTo(CaseInstance::class);
    }
}
