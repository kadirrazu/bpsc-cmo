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

    public function downloads(Request $request)
    {
        $data = $request->validate(['candidate_downloads_enabled' => 'required|boolean']);
        DB::transaction(function () use ($data) {
            $settings = VivaSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $previous = $settings->candidate_downloads_enabled;
            $settings->candidate_downloads_enabled = (bool) $data['candidate_downloads_enabled'];
            $settings->save();
            app(Audit::class)->record('viva.candidate-downloads.updated', ['previous' => $previous, 'enabled' => $settings->candidate_downloads_enabled]);
        });

        return back()->with('success', 'Candidate PDF download setting saved.');
    }

    public function timing(Request $request)
    {
        $data = $request->validate(['start_time' => 'required|date_format:H:i']);
        DB::transaction(function () use ($data) {
            $settings = VivaSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $before = $settings->start_time;
            $settings->update($data);
            app(Audit::class)->record('viva.timing.updated', $data + ['previous_start_time' => $before]);
        });

        return back()->with('success', 'Distribution opening time saved.');
    }

    public function distributionDownloads(Request $request)
    {
        $data = $request->validate(['distribution_downloads_enabled' => 'required|boolean']);
        DB::transaction(function () use ($data) {
            $settings = VivaSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $previous = $settings->distribution_downloads_enabled;
            $settings->distribution_downloads_enabled = (bool) $data['distribution_downloads_enabled'];
            $settings->save();
            app(Audit::class)->record('viva.distribution-downloads.updated', ['previous' => $previous, 'enabled' => $settings->distribution_downloads_enabled]);
        });

        return back()->with('success', 'Chairman distribution download setting saved.');
    }

    public function update(Request $r)
    {
        $d = $r->validate(['start_time' => 'sometimes|date_format:H:i', 'display_username' => 'required|string|max:100', 'display_password' => ['nullable', 'max:72', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()]]);
        DB::transaction(function () use ($d) {
            $settings = VivaSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            if (! $settings->display_password && empty($d['display_password'])) {
                throw ValidationException::withMessages(['display_password' => 'Set a display password before enabling shared display access.']);
            }
            $changed = $settings->display_username !== $d['display_username'] || ! empty($d['display_password']);
            if (empty($d['display_password'])) {
                unset($d['display_password']);
            }
            $before = $settings->only(['start_time', 'display_username']);
            $settings->fill($d);
            if ($changed) {
                $settings->auth_version++;
            } $settings->save();
            app(Audit::class)->record('viva.settings.updated', ['start_time' => $settings->start_time, 'display_username' => $settings->display_username, 'credentials_changed' => $changed, 'before' => $before]);
        });

        return back()->with('success', 'Viva settings saved. Changed display credentials revoke existing display sessions.');
    }
}
