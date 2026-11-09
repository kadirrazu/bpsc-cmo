<?php

namespace App\Services\Library;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    /** Only callers' allowlisted data is accepted; request payloads/tokens/passwords are never captured. */
    public function record(string $action, array $details = [], ?Model $subject = null, ?User $actor = null): void
    {
        $request = app()->bound('request') ? request() : null;
        $actor ??= $request?->user();
        $console = app()->runningInConsole() && ! $request?->route();
        AuditLog::create([
            'actor_id' => $actor?->id, 'actor_name' => $actor?->name, 'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null, 'subject_id' => $subject?->getKey(),
            'channel' => $console ? 'console' : ($request?->is('api/*') ? 'api' : 'web'),
            'ip_address' => $console ? null : $request?->ip(), 'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500),
            'details' => $details, 'created_at' => now(),
        ]);
    }
}
