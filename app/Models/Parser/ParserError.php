<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParserError extends Model
{
    protected $fillable = [
        'parser_run_id',
        'court_id',
        'url',
        'error_type',
        'error_message',
        'traceback',
        'is_resolved',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'is_resolved' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function parserRun(): BelongsTo
    {
        return $this->belongsTo(ParserRun::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }
}
