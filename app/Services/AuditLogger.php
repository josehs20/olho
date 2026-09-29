<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(?User $actor, string $action, ?Model $subject = null, array $metadata = [], ?string $ipAddress = null): AuditLog
    {
        $audit = AuditLog::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
        ]);

        if ($subject !== null) {
            $audit->subject()->associate($subject);
            $audit->save();
        }

        return $audit;
    }
}
