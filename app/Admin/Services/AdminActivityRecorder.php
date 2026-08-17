<?php

namespace App\Admin\Services;

use App\Models\Admin\AdminActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AdminActivityRecorder
{
    /** @param array<string, mixed> $context */
    public function record(User $user, string $action, ?Model $subject = null, array $context = [], ?string $ipAddress = null): AdminActivityLog
    {
        return AdminActivityLog::query()->create([
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context_json' => $context === [] ? null : $context,
            'ip_address' => $ipAddress,
        ]);
    }
}
