<?php

namespace App\Services\Library;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Audit
{
    /** Record business metadata, never a raw request body or a file's contents. */
    public function record(string $action, array $details = [], ?Model $subject = null, ?User $actor = null): void
    {
        $request = app()->bound('request') ? request() : null;
        $http = $request && ($request->route() || $request->attributes->get('audit_http'));
        $console = app()->runningInConsole() && ! $http;
        $actor ??= $console ? null : $request?->user();
        $display = ! $console && $request?->routeIs('viva.display.*');
        if ($display) {
            $actor = null;
            $details['principal'] = 'shared_viva_display';
        }

        AuditLog::create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->role?->value,
            'request_uuid' => $console || ! $request ? null : $this->requestId($request),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'channel' => $console ? 'console' : ($request?->is('api/*') ? 'api' : 'web'),
            'ip_address' => $console ? null : $request?->ip(),
            'user_agent' => $console ? null : mb_substr((string) $request?->userAgent(), 0, 500),
            'details' => $this->redact($details),
            'created_at' => now(),
        ]);
    }

    public function requestId(Request $request): string
    {
        $id = $request->attributes->get('audit_request_uuid') ?? (string) Str::uuid();
        $request->attributes->set('audit_request_uuid', $id);

        return $id;
    }

    private function redact(array $details): array
    {
        $safeFlags = ['password_changed', 'credentials_changed', 'credentials_included', 'password_setup'];
        foreach ($details as $key => $value) {
            if (is_string($key) && ! in_array($key, $safeFlags, true) &&
                preg_match('/password|passphrase|secret|token|cookie|authorization|credential|payload|api[_-]?key/i', $key)) {
                unset($details[$key]);
            } elseif (is_array($value)) {
                $details[$key] = $this->redact($value);
            }
        }

        return $details;
    }
}
