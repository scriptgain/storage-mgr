<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\OnlineLicenseCheck;
use App\Services\UpdateService;
use Illuminate\Http\Request;

class UpdateController extends Controller
{
    /**
     * Every action here changes install-global settings, so all of them are
     * admin-only. Gated once in the constructor rather than per method so a
     * new action cannot be added without the check. (Security fix: these
     * settings pages were reachable by any authenticated non-admin user.)
     */
    public function __construct()
    {
        abort_unless(auth()->user()?->isAdmin(), 403, 'Admins only.');
    }

    public function show()
    {
        return view('settings.updates', ['status' => UpdateService::status()]);
    }

    public function check()
    {
        (new OnlineLicenseCheck)->check();

        return back()->with('status', 'Checked for updates.');
    }

    public function apply()
    {
        if (! UpdateService::available()) {
            return back()->with('status', 'Already up to date.');
        }
        Setting::put('update_requested', '1');
        Setting::put('update_last_result', 'queued: requested ' . now()->toIso8601String());

        return back()->with('status', 'Update queued — it will start within a minute. Refresh for the result.');
    }

    public function toggleAuto(Request $request)
    {
        Setting::put('update_auto', $request->boolean('auto') ? '1' : '0');

        return back()->with('status', 'Automatic updates ' . ($request->boolean('auto') ? 'enabled.' : 'disabled.'));
    }
}
