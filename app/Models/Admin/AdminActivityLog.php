<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminActivityLog extends Model
{
    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'context_json', 'ip_address'];

    protected function casts(): array
    {
        return ['context_json' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
