<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseParty extends Model
{
    protected $fillable = [
        'case_instance_id',
        'role',
        'party_type',
        'source_role',
        'confidence',
    ];

    public function caseInstance(): BelongsTo
    {
        return $this->belongsTo(CaseInstance::class);
    }
}
