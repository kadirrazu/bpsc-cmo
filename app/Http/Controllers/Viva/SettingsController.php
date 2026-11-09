<?php

namespace App\Http\Controllers\Viva;

use App\Http\Controllers\Controller;
use App\Models\VivaSetting;
use App\Services\Library\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function edit()
    {
        return view('viva.settings', ['settings' => VivaSetting::findOrFail(1)]);
    }

    public function update(Request $r)
    {
        $d = $r->validate(['start_time' => 'required|date_format:H:i', 'display_username' => 'required|string|max:100', 'display_password' => ['nullable', 'max:72', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()]]);
        DB::transaction(function () use ($d) {
            $settings = VivaSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            if (! $settings->display_password && empty($d['display_password'])) {
                throw ValidationException::withMessages(['display_password' => 'Set a display password before enabling shared display access.']);
            }
            $changed = $settings->display_username !== $d['display_username'] || ! empty($d['display_password']);
            if (empty($d['display_password'])) {
                unset($d['display_password']);
            }
            $settings->fill($d);
            if ($changed) {
                $settings->auth_version++;
            } $settings->save();
            app(Audit::class)->record('viva.settings.updated', ['start_time' => $settings->start_time, 'display_username' => $settings->display_username, 'credentials_changed' => $changed]);
        });

        return back()->with('success', 'Viva settings saved. Changed display credentials revoke existing display sessions.');
    }
}
