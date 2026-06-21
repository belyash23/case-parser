<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestLog extends Model
{
    protected $fillable = [
        'parser_run_id',
        'court_id',
        'url',
        'url_hash',
        'status_code',
        'duration_ms',
        'response_size_bytes',
        'retry_count',
        'error_type',
        'error_message',
    ];

    public function parserRun(): BelongsTo
    {
        return $this->belongsTo(ParserRun::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }
}
