<?php

namespace App\Http\Middleware;

use App\Services\OfflineLicenseVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * License lockdown.
 *
 * Locks the panel whenever the EFFECTIVE license state (the more restrictive of
 * the offline .lic state and the periodic online-validation state) is present but
 * NOT 'valid' (expired / stale / revoked / tampered / invalid). Every route except
 * authentication and the license settings page redirects to the license page,
 * where a prominent non-dismissible banner explains why and offers a fix.
 *
 * When neither an uploaded .lic nor an online-validated key is bad, effectiveState
 * is null and nothing is blocked (safe default). This makes the middleware safe to
 * deploy fleet-wide: it only ever bites an instance whose license has gone bad.
 */
class EnforceLicense
{
    /** Route names always reachable, even while locked. */
    private array $allow = [
        'login', 'logout', 'magic-login', '2fa.challenge',
        'settings.license.edit', 'settings.license.update',
        'settings.license.upload', 'settings.license.sync',
        'settings.license.file.remove', 'settings.license.check',
        'favicon.svg', 'favicon.png', 'favicon.apple',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $state = OfflineLicenseVerifier::effectiveState()['state'];
        if ($state === null || $state === 'valid') {
            return $next($request);
        }

        $route = $request->route()?->getName();
        if ($route && in_array($route, $this->allow, true)) {
            return $next($request);
        }

        // API / JSON callers get a clean 403 rather than an HTML redirect.
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'This instance is locked: license '.$state.'.'], 403);
        }

        return redirect()->route('settings.license.edit')
            ->with('warning', 'This software is locked because its license is '.$state.'. Restore a valid license to regain access.');
    }
}
