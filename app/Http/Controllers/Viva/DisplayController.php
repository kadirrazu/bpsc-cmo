<?php

namespace App\Http\Controllers\Viva;

use App\Http\Controllers\Controller;
use App\Models\VivaSetting;
use App\Services\Library\Audit;
use App\Services\Viva\DisplayData;
use App\Services\Viva\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DisplayController extends Controller
{
    public function loginForm()
    {
        return view('viva.display.login', ['configured' => (bool) VivaSetting::findOrFail(1)->display_password]);
    }

    public function login(Request $r)
    {
        $d = $r->validate(['username' => 'required|string|max:100', 'password' => 'required|string|max:255']);
        $settings = VivaSetting::findOrFail(1);
        if (! $settings->display_password || ! hash_equals((string) $settings->display_username, $d['username']) || ! Hash::check($d['password'], $settings->display_password)) {
            app(Audit::class)->record('viva.display.login.failed');
            throw ValidationException::withMessages(['username' => 'Invalid display credentials or display access has not been configured.']);
        }
        $r->session()->regenerate();
        $r->session()->put('viva_display', ['version' => $settings->auth_version, 'expires' => now()->addHours(12)->timestamp]);
        app(Audit::class)->record('viva.display.login');

        return redirect()->route('viva.display.index');
    }

    public function logout(Request $r)
    {
        $r->session()->forget('viva_display');
        $r->session()->regenerate();
        app(Audit::class)->record('viva.display.logout');

        return redirect()->route('viva.display.login');
    }

    public function index(Request $r, Workspace $workspace, DisplayData $display)
    {
        $r->validate(['vdate' => 'nullable|date_format:Y-m-d']);
        $date = $workspace->date($r->input('vdate'));
        $assignments = $display->assignments($date);
        $payload = $display->payload($assignments, $date);

        return view('viva.display.index', compact('date', 'payload'));
    }

    public function feed(Request $r, Workspace $workspace, DisplayData $display)
    {
        $r->validate(['vdate' => 'nullable|date_format:Y-m-d']);
        $date = $workspace->date($r->input('vdate'));
        $assignments = $display->assignments($date);
        $version = $display->version($assignments, $date);
        if ($r->header('If-None-Match') === $version) {
            return response('', 304)->header('ETag', $version);
        }

        return response()->json($display->payload($assignments, $date))->header('ETag',$version);
    }
}
