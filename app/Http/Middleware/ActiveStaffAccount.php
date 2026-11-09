<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActiveStaffAccount
{
    public function handle(Request $request, Closure $next)
    {
        if ($user = $request->user()) {
            $current = User::find($user->id);
            if (! $current || ! $current->is_active || (int) $request->session()->get('auth_version', 0) !== (int) $current->auth_version) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['email' => __('Please sign in again. Your account or access has changed.')]);
            }
            Auth::guard('web')->setUser($current);
            $request->setUserResolver(fn () => $current);
        }

        return $next($request);
    }
}
