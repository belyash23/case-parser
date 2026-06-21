<?php

namespace App\Models\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RawPage extends Model
{
    protected $fillable = [
        'court_id',
        'url',
        'url_hash',
        'page_type',
        'fetched_at',
        'http_status',
        'content_hash',
        'sanitized_html_path',
        'parser_version',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function caseInstances(): HasMany
    {
        return $this->hasMany(CaseInstance::class);
    }
}
