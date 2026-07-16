<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Housekeeping sweep. The command enforces the configured maintenance window,
// so it is safe to attempt hourly (installer wires `schedule:run` cron).
Schedule::command('storage:maintenance')->hourly()->withoutOverlapping();

// Periodic ONLINE license validation against ScriptGain (~every 2 days).
// Verifies the signed /v1/validate response and drives the same lockdown as the
// offline .lic path. Safe to run when no key is configured (it no-ops).
Schedule::command('license:check-online')->cron('37 3 */2 * *')->withoutOverlapping();
