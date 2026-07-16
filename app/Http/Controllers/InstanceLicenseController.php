<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\OfflineLicenseVerifier;
use App\Services\OnlineLicenseCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * This *instance's own* product license (the license that authorizes running
 * this software, issued by the vendor). Distinct from LicenseController, which
 * manages the license keys this panel issues to customers.
 *
 * Two mechanisms live here:
 *  - Online key: a human-readable key validated periodically against ScriptGain's
 *    signed /v1/validate API (see OnlineLicenseCheck).
 *  - Offline .lic file: a ScriptGain-signed JSON license verified locally against
 *    the embedded public key (see OfflineLicenseVerifier).
 * The more restrictive of the two states drives the lockdown banner + middleware.
 */
class InstanceLicenseController extends Controller
{
    public function edit()
    {
        // Refresh the offline state (cheap; cached) before rendering.
        OfflineLicenseVerifier::currentState();

        $license = [
            'key' => Setting::get('license_key'),
            'plan' => Setting::get('license_plan'),
            'status' => Setting::get('license_status', 'unlicensed'),
            'checked_at' => Setting::get('license_checked_at'),
            'product' => config('brand.name', 'LicenseManager'),
        ];

        $offline = [
            'present' => (bool) Setting::get('license_lic'),
            'state' => Setting::get('license_state'),
            'message' => Setting::get('license_message'),
            'product' => Setting::get('license_product'),
            'type' => Setting::get('license_type'),
            'expires_at' => Setting::get('license_lic_expires_at'),
            'offline_expires_at' => Setting::get('license_offline_expires_at'),
            'checked_at' => Setting::get('license_checked_at'),
        ];

        $intervalDays = (int) config('licensing.online_check_interval_days', 2);
        $onlineCheckedAt = Setting::get('license_online_checked_at');
        $online = [
            'configured' => (bool) trim((string) Setting::get('license_key')),
            'state' => Setting::get('license_online_state'),
            'reason' => Setting::get('license_online_reason'),
            'message' => Setting::get('license_online_message'),
            'checked_at' => $onlineCheckedAt,
            'last_success_at' => Setting::get('license_online_last_success_at'),
            'last_error' => Setting::get('license_online_last_error'),
            'product' => Setting::get('license_online_product'),
            'expires_at' => Setting::get('license_online_expires_at'),
            'seats' => Setting::get('license_online_seats'),
            'next_due_at' => $onlineCheckedAt
                ? Carbon::parse($onlineCheckedAt)->addDays($intervalDays)->toDateTimeString()
                : null,
        ];

        return view('settings.license', compact('license', 'offline', 'online'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['nullable', 'string', 'max:200'],
        ]);
        $key = trim((string) ($data['license_key'] ?? ''));

        Setting::put('license_key', $key ?: null);
        Setting::put('license_status', $key ? 'unverified' : 'unlicensed');

        // Clearing the key retires the online lockdown state so a removed key can
        // never leave a stale lock behind.
        if (! $key) {
            foreach ([
                'license_online_state', 'license_online_reason', 'license_online_message',
                'license_online_expires_at', 'license_online_product', 'license_online_seats',
                'license_online_last_error',
            ] as $k) {
                Setting::put($k, null);
            }
        }

        AuditLog::record('license', $key ? 'Updated license key' : 'Cleared license key');

        return back()->with('status', $key ? 'License Key Saved. Run Check License Now To Validate It.' : 'License Key Cleared.');
    }

    public function sync(Request $request)
    {
        $key = Setting::get('license_key');
        if (! $key) {
            return back()->with('status', 'Enter a License Key first.');
        }

        // Sync now performs a real online validation.
        return $this->checkNow($request);
    }

    /**
     * Run an immediate online validation against ScriptGain and flash the result.
     */
    public function checkNow(Request $request)
    {
        $key = Setting::get('license_key');
        if (! trim((string) $key)) {
            return back()->with('warning', 'Enter A License Key First, Then Check.');
        }

        $r = (new OnlineLicenseCheck)->check();
        $state = $r['state'] ?? null;
        AuditLog::record('license', 'Online license check: '.($state ?? 'unchanged').' ('.($r['reason'] ?? 'ok').')');

        if (! empty($r['inconclusive'])) {
            return back()->with('warning', 'Check Inconclusive: '.$r['message'].' The License State Was Not Changed.');
        }

        if ($state === 'valid') {
            return back()->with('status', 'License Validated Online. This Instance Is Licensed.');
        }

        return back()->with('warning', 'License '.ucfirst((string) $state).': '.$r['message']);
    }

    /**
     * Upload and verify a ScriptGain-signed .lic file. Verification is fully
     * offline (embedded public key). The verified state is persisted and drives
     * the lockdown banner + middleware.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'license_file' => ['required', 'file', 'max:64'], // KB; a .lic is tiny
        ]);

        $raw = (string) file_get_contents($request->file('license_file')->getRealPath());
        $result = (new OfflineLicenseVerifier)->store($raw);

        AuditLog::record('license', 'Uploaded license file (state: '.$result['state'].')');

        if ($result['state'] === 'valid') {
            return back()->with('status', 'License File Verified. This Instance Is Licensed.');
        }

        return back()->with('warning', 'License File Uploaded, But It Is '.ucfirst($result['state']).': '.$result['message']);
    }

    /** Remove the uploaded .lic file and revert to online-key mode. */
    public function removeFile(Request $request)
    {
        (new OfflineLicenseVerifier)->forget();
        AuditLog::record('license', 'Removed uploaded license file');

        return back()->with('status', 'License File Removed.');
    }
}
