<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('view-logs');
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'actor_id' => 'nullable|integer|min:1', 'action' => 'nullable|string|max:80', 'ip_address' => 'nullable|ip', 'subject_type' => 'nullable|string|max:100', 'subject_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1']);
        $logs = AuditLog::when($d['subject_type'] ?? null, fn ($q, $s) => $q->where('subject_type', $s))->when($d['subject_id'] ?? null, fn ($q, $s) => $q->where('subject_id', $s))->when($d['from'] ?? null, fn ($q, $s) => $q->where('created_at', '>=', $s.' 00:00:00'))
            ->when($d['to'] ?? null, fn ($q, $s) => $q->where('created_at', '<=', $s.' 23:59:59'))
            ->when($d['actor_id'] ?? null, fn ($q, $s) => $q->where('actor_id', $s))
            ->when($d['action'] ?? null, fn ($q, $s) => $q->where('action', $s))->when($d['ip_address'] ?? null, fn ($q, $s) => $q->where('ip_address', $s))->latest('id')->paginate(50)->withQueryString();

        return $r->is('api/*') ? response()->json($logs) : view('audits.index', compact('logs'));
    }
}
