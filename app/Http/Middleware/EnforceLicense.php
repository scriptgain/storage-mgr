<?php

namespace App\Http\Middleware;

use App\Services\OfflineLicenseVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * License lockdown.
 *
 * Hard-locks the panel whenever the EFFECTIVE license state (the more restrictive
 * of the offline .lic state and the periodic online-validation state) is a
 * signature-verified GENUINE REJECTION: expired / invalid (revoked, suspended,
 * not_found, over-limit) / tampered. Every management route then redirects to a
 * dedicated "License Key Invalid" lockdown page that offers recovery (re-sync +
 * enter a new key).
 *
 * Lenient states are deliberately NOT hard-locked: 'stale' (the offline window
 * lapsed, or the online grace expired while the server was unreachable) and the
 * transient inconclusive / unreachable outcomes only raise the banner. None of
 * those is a verified rejection, so locking on them would punish a network hiccup.
 * When neither source reports a bad state the effective state is null and nothing
 * is blocked (safe default), so this middleware is safe to run fleet-wide: it only
 * ever bites an instance whose license has genuinely gone bad.
 */
class EnforceLicense
{
    /** States that constitute a genuine, signature-verified rejection: HARD lock. */
    public const HARD_LOCK = ['expired', 'invalid', 'tampered'];

    /** Route names always reachable, even while hard-locked. */
    private array $allow = [
        // Authentication.
        'login', 'logout', 'magic-login', '2fa.challenge',
        // First-run wizard.
        'setup.index', 'setup.admin', 'setup.license',
        // The lockdown page + its two recovery actions.
        'license.locked', 'license.resync', 'license.rekey',
        // Public brand / favicon assets.
        'favicon.svg', 'favicon.png', 'favicon.apple',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $state = OfflineLicenseVerifier::effectiveState()['state'];

        // Only a genuine rejection hard-locks. null / valid / stale pass through
        // (stale still raises the banner, but never blocks management).
        if (! in_array($state, self::HARD_LOCK, true)) {
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

        return redirect()->route('license.locked');
    }
}
