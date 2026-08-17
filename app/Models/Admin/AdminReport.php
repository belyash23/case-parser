<?php

namespace App\Models\Admin;

use App\Enums\Admin\ReportStatus;
use App\Enums\Admin\ReportType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminReport extends Model
{
    protected $attributes = ['status' => 'queued'];

    protected $fillable = [
        'user_id', 'type', 'format', 'status', 'filters_json', 'path', 'size_bytes',
        'error_message', 'started_at', 'finished_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'status' => ReportStatus::class,
            'filters_json' => 'array',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
