<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Online License Validation
    |--------------------------------------------------------------------------
    |
    | This instance validates its license key against ScriptGain's signed
    | validation API on a schedule. Every response is RSA-SHA256 signed and
    | verified locally against ScriptGain's embedded public key before it is
    | allowed to change the lockdown state.
    |
    */

    // ScriptGain's validation endpoint. POST {"key": "..."} -> signed response.
    'validate_url' => env('LICENSE_VALIDATE_URL', 'https://scriptgain.com/v1/validate'),

    // This product's ScriptGain slug. Passed to the compiled guard for reporting
    // (product_match); mismatch is currently advisory, not enforced.
    'product' => env('LICENSE_PRODUCT'),

    // The compiled license-enforcement helper. When present and executable, the
    // RSA signature verification + state decision run in this binary instead of
    // inline PHP. When absent, the app falls back to PHP verification (fail-soft).
    'guard_binary' => env('LICENSE_GUARD_BINARY', base_path('bin/licenseguard')),

    // Vendor-signed release manifest for the guard binary (anti-tamper LAYER 2).
    // {"manifest":{"version","sha256"},"signature": RSA-SHA256 over its canonical
    // form}. PHP verifies the signature against the embedded public key and uses
    // that sha256 as the trusted expected hash — a customer cannot forge it.
    'guard_manifest' => env('LICENSE_GUARD_MANIFEST', base_path('bin/licenseguard.manifest.json')),

    // LAST-RESORT baseline only: used when no signed manifest is present (first run
    // / fully offline). Patchable by design; the manifest above is the real check.
    // Empty disables the fallback. Update on every guard rebuild.
    'guard_sha256' => env('LICENSE_GUARD_SHA256', '7593ce44cff3194003c7774b7e12adee49fe954f553840bf3adc69e64a34396a'),

    // How often the online check runs (scheduled + opportunistic boot check).
    'online_check_interval_days' => (int) env('LICENSE_ONLINE_INTERVAL_DAYS', 2),

    // If the validation server cannot be reached, keep the last known state for
    // this many days before escalating to a locked 'stale' state.
    'online_grace_days' => (int) env('LICENSE_ONLINE_GRACE_DAYS', 7),

];
