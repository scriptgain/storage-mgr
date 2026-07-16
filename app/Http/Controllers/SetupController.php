<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\OfflineLicenseVerifier;
use App\Services\OnlineLicenseCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One-time first-run setup wizard.
 *
 * Two steps:
 *   1. Create the first admin account (guest).
 *   2. Enter/activate a license key (authed as that admin).
 *
 * Access is governed entirely by the EnsureSetup middleware; these routes are
 * deliberately NOT behind the auth middleware so step 1 can run as a guest.
 * These panels are lenient: an unreachable/unverifiable vendor still lets the
 * operator finish. Only a genuine, ScriptGain-signed rejection blocks.
 */
class SetupController extends Controller
{
    public function index()
    {
        // Step 1: no admin yet -> create one.
        if (User::where('role', 'admin')->doesntExist()) {
            return view('setup.admin');
        }

        // Step 2: admin exists but no confirmed-good license -> license step.
        $key = trim((string) Setting::get('license_key'));
        $state = OfflineLicenseVerifier::effectiveState()['state'] ?? null;

        if ($key === '' || $state !== 'valid') {
            return view('setup.license', [
                'state' => $state,
            ]);
        }

        // Everything satisfied — mark complete and go home.
        Setting::put('setup_complete', '1');

        return redirect()->route('dashboard');
    }

    public function storeAdmin(Request $request)
    {
        // Guard: don't let this be used to add a second admin once one exists.
        if (User::where('role', 'admin')->exists()) {
            return redirect()->route('setup.index');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            // password cast as 'hashed' on the model — plain value is hashed on save.
            'password' => $data['password'],
            'role' => 'admin',
        ]);

        Auth::login($user);

        return redirect()->route('setup.index');
    }

    public function storeLicense(Request $request)
    {
        $data = $request->validate([
            'key' => ['nullable', 'string', 'max:255'],
        ]);

        // Secondary "I'll add my license later" action — finish without a key.
        if ($request->input('action') === 'skip' || blank($data['key'] ?? null)) {
            Setting::put('setup_complete', '1');

            return redirect()->route('dashboard')
                ->with('warning', 'Setup complete. Add a license key later from Settings → License.');
        }

        // Store the key exactly where OnlineLicenseCheck reads it.
        Setting::put('license_key', trim($data['key']));

        // Run one online validation cycle. Never throws; returns a result array
        // of shape ['state' => valid|expired|invalid|stale|null, 'reason', 'message', 'inconclusive'?].
        $r = (new OnlineLicenseCheck)->check();
        $state = $r['state'] ?? null;
        $message = $r['message'] ?? '';
        $inconclusive = ($r['inconclusive'] ?? false) === true;

        // Genuine, signature-verified valid -> done.
        if ($state === 'valid') {
            Setting::put('setup_complete', '1');

            return redirect()->route('dashboard')
                ->with('success', 'License activated — setup complete.');
        }

        // Genuine, signature-verified rejection (expired/invalid/revoked/not_found):
        // NOT flagged inconclusive. Clear the bad key and let them retry.
        if (! $inconclusive && in_array($state, ['expired', 'invalid'], true)) {
            Setting::put('license_key', null);

            return redirect()->route('setup.index')
                ->withErrors(['key' => 'That license key was rejected: '.$message]);
        }

        // Network couldn't confirm (unreachable / grace) or the response signature
        // was not verifiable (inconclusive / MITM). Never hard-lock: store the key
        // and finish; it will verify automatically once the vendor is reachable.
        Setting::put('setup_complete', '1');

        return redirect()->route('dashboard')
            ->with('warning', 'Setup complete. Your license will verify automatically once the vendor is reachable.');
    }
}
