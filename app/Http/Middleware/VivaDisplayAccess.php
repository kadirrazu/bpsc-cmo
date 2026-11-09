<?php

namespace App\Http\Middleware;

use App\Models\VivaSetting;
use Closure;
use Illuminate\Http\Request;

class VivaDisplayAccess
{
    public function handle(Request $r, Closure $next)
    {
        $settings = VivaSetting::findOrFail(1);
        $session = $r->session()->get('viva_display');
        if (! $settings->display_password || ! $session || ($session['version'] ?? null) !== $settings->auth_version || ($session['expires'] ?? 0) <= now()->timestamp) {
            $r->session()->forget('viva_display');
            if ($r->expectsJson()) {
                return response()->json(['message' => 'Display sign-in required.', 'login_url' => route('viva.display.login')], 401);
            }

            return redirect()->route('viva.display.login');
        }

        return $next($r);
    }
}
