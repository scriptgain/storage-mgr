<?php

use App\Http\Controllers\AccessKeyController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\BucketController;
use App\Http\Controllers\BucketObjectController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FaviconController;
use App\Http\Controllers\FirewallController;
use App\Http\Controllers\HostSslController;
use App\Http\Controllers\GeneralSettingsController;
use App\Http\Controllers\InstanceLicenseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Guest (unauthenticated) routes.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

// One-click signed magic-login link (short-lived; the signature is the credential).
Route::get('/magic/{user}', [AuthController::class, 'magic'])->name('magic-login')->middleware('signed');

// Two-factor challenge (after password, before full login).
Route::get('/2fa', [AuthController::class, 'challenge'])->name('2fa.challenge');
Route::post('/2fa', [AuthController::class, 'challengeVerify'])->middleware('throttle:10,1');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// License lockdown recovery (reachable while hard-locked; EnforceLicense allow-lists these).
Route::middleware('auth')->group(function () {
    Route::get('/license/locked', [InstanceLicenseController::class, 'locked'])->name('license.locked');
    Route::post('/license/resync', [InstanceLicenseController::class, 'resync'])->name('license.resync');
    Route::post('/license/rekey', [InstanceLicenseController::class, 'rekey'])->name('license.rekey');
});

// Brand favicon (public).
Route::get('/brand/favicon', [FaviconController::class, 'svg'])->name('favicon.svg');
Route::get('/brand/favicon-png', [FaviconController::class, 'faviconPng'])->name('favicon.png');
Route::get('/brand/favicon-apple', [FaviconController::class, 'appleIcon'])->name('favicon.apple');

// Authenticated control panel.
Route::middleware(['auth', 'security.policy'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Buckets + the object browser nested inside a bucket.
    Route::resource('buckets', BucketController::class);
    Route::post('buckets/{bucket}/objects', [BucketObjectController::class, 'store'])->name('buckets.objects.store');
    Route::delete('buckets/{bucket}/objects/{object}', [BucketObjectController::class, 'destroy'])->name('buckets.objects.destroy');

    // Access keys (S3-style credential pairs). No edit screen — status toggles instead.
    Route::resource('access-keys', AccessKeyController::class)->except(['show', 'edit', 'update']);
    Route::post('access-keys/{access_key}/status', [AccessKeyController::class, 'setStatus'])->name('access-keys.setstatus');

    // Policies (S3-style JSON documents attachable to access keys).
    Route::resource('policies', PolicyController::class);

    // Settings.
    Route::view('/settings', 'settings.index')->name('settings.index');
    // Email Delivery (transport, sender identity, test send).
    Route::get('settings/email', [\App\Http\Controllers\EmailSettingsController::class, 'edit'])->name('settings.email.edit');
    Route::put('settings/email', [\App\Http\Controllers\EmailSettingsController::class, 'update'])->name('settings.email.update');
    Route::post('settings/email/test', [\App\Http\Controllers\EmailSettingsController::class, 'test'])->name('settings.email.test');
    Route::get('settings/tokens', [ApiTokenController::class, 'index'])->name('settings.tokens.index');
    Route::post('settings/tokens', [ApiTokenController::class, 'store'])->name('settings.tokens.store');
    Route::delete('settings/tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('settings.tokens.destroy');
    Route::get('settings/password', [PasswordController::class, 'edit'])->name('settings.password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('settings.password.update');
    Route::get('settings/license', [InstanceLicenseController::class, 'edit'])->name('settings.license.edit');
    Route::put('settings/license', [InstanceLicenseController::class, 'update'])->name('settings.license.update');
    Route::post('settings/license/sync', [InstanceLicenseController::class, 'sync'])->name('settings.license.sync');
    Route::post('settings/license/upload', [InstanceLicenseController::class, 'upload'])->name('settings.license.upload');
    Route::delete('settings/license/file', [InstanceLicenseController::class, 'removeFile'])->name('settings.license.file.remove');
    Route::post('settings/license/check-online', [InstanceLicenseController::class, 'checkNow'])->name('settings.license.check');
    Route::get('settings/branding', [BrandingController::class, 'edit'])->name('settings.branding.edit');
    Route::put('settings/branding', [BrandingController::class, 'update'])->name('settings.branding.update');
    Route::get('settings/2fa', [TwoFactorController::class, 'show'])->name('settings.2fa.show');
    Route::post('settings/2fa/enable', [TwoFactorController::class, 'enable'])->name('settings.2fa.enable');
    Route::post('settings/2fa/confirm', [TwoFactorController::class, 'confirm'])->name('settings.2fa.confirm');
    Route::delete('settings/2fa', [TwoFactorController::class, 'disable'])->name('settings.2fa.disable');
    Route::get('settings/notifications', [NotificationController::class, 'edit'])->name('settings.notifications.edit');
    Route::put('settings/notifications', [NotificationController::class, 'update'])->name('settings.notifications.update');
    Route::post('settings/notifications/test', [NotificationController::class, 'test'])->name('settings.notifications.test');
    Route::get('settings/users', [UserController::class, 'index'])->name('settings.users.index');
    Route::get('settings/users/create', [UserController::class, 'create'])->name('settings.users.create');
    Route::post('settings/users', [UserController::class, 'store'])->name('settings.users.store');
    Route::get('settings/users/{user}/edit', [UserController::class, 'edit'])->name('settings.users.edit');
    Route::put('settings/users/{user}', [UserController::class, 'update'])->name('settings.users.update');
    Route::delete('settings/users/{user}', [UserController::class, 'destroy'])->name('settings.users.destroy');
    Route::get('settings/audit', [AuditLogController::class, 'index'])->name('settings.audit.index');
    Route::delete('settings/audit/selected', [AuditLogController::class, 'destroySelected'])->name('settings.audit.destroy-selected');
    Route::delete('settings/audit/all', [AuditLogController::class, 'destroyAll'])->name('settings.audit.destroy-all');
    Route::get('settings/general', [GeneralSettingsController::class, 'edit'])->name('settings.general.edit');
    Route::put('settings/general', [GeneralSettingsController::class, 'update'])->name('settings.general.update');

    // Firewall (admin-gated in the controller): sessions, IP bans, access limit.
    Route::get('settings/firewall', [FirewallController::class, 'index'])->name('settings.firewall.index');
    Route::put('settings/firewall', [FirewallController::class, 'update'])->name('settings.firewall.update');
    Route::post('settings/firewall/bans', [FirewallController::class, 'ban'])->name('settings.firewall.ban');
    Route::delete('settings/firewall/bans/{bannedIp}', [FirewallController::class, 'unban'])->name('settings.firewall.unban');
    Route::delete('settings/firewall/sessions/{id}', [FirewallController::class, 'revokeSession'])->name('settings.firewall.session.revoke');
    Route::post('settings/firewall/sessions/bulk', [FirewallController::class, 'bulkSessions'])->name('settings.firewall.sessions.bulk');
    Route::post('settings/firewall/bulk', [FirewallController::class, 'bulk'])->name('settings.firewall.bulk');

    // Host & SSL (admin-gated in the controller): hostname + certificate management.
    Route::get('settings/host', [HostSslController::class, 'edit'])->name('settings.host.edit');
    Route::put('settings/host', [HostSslController::class, 'update'])->name('settings.host.update');
    Route::post('settings/host/letsencrypt', [HostSslController::class, 'letsencrypt'])->name('settings.host.letsencrypt');
    Route::post('settings/host/upload', [HostSslController::class, 'upload'])->name('settings.host.upload');
    Route::post('settings/host/self-signed', [HostSslController::class, 'selfSigned'])->name('settings.host.self-signed');

    // Maintenance (bucket housekeeping, access-key hygiene, audit pruning + window).
    Route::get('settings/maintenance', [\App\Http\Controllers\MaintenanceController::class, 'edit'])->name('settings.maintenance.edit');
    Route::put('settings/maintenance', [\App\Http\Controllers\MaintenanceController::class, 'update'])->name('settings.maintenance.update');
    Route::post('settings/maintenance/run', [\App\Http\Controllers\MaintenanceController::class, 'runNow'])->name('settings.maintenance.run');
});

// First-run setup wizard. Not behind 'auth': step 1 (create admin) runs as a
// guest, step 2 (license) runs authed. Access is governed by EnsureSetup.
Route::prefix('setup')->group(function () {
    Route::get('/', [SetupController::class, 'index'])->name('setup.index');
    Route::post('/admin', [SetupController::class, 'storeAdmin'])->name('setup.admin');
    Route::post('/license', [SetupController::class, 'storeLicense'])->name('setup.license');
});
