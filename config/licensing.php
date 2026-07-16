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

    // How often the online check runs (scheduled + opportunistic boot check).
    'online_check_interval_days' => (int) env('LICENSE_ONLINE_INTERVAL_DAYS', 2),

    // If the validation server cannot be reached, keep the last known state for
    // this many days before escalating to a locked 'stale' state.
    'online_grace_days' => (int) env('LICENSE_ONLINE_GRACE_DAYS', 7),

];
