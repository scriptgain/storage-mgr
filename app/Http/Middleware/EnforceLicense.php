<?php

namespace App\Http\Middleware;

use App\Services\OfflineLicenseVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * License lockdown.
 *
 * Hard-locks the panel only on a MALICIOUS or DEAD license: a tampered/forged
 * signature, or a signature-verified authoritative rejection ('invalid' =
 * revoked / suspended / not_found / over-limit). Those get no benefit of the
 * doubt — every management route redirects to a dedicated "License Key Invalid"
 * lockdown page that offers recovery (re-sync + enter a new key).
 *
 * An HONEST LAPSE is treated gently: 'expired' (validly signed, just past its
 * date) does NOT hard-lock — it raises the renewal banner and keeps working, so
 * a customer is never bricked mid-work. Likewise 'stale' (offline window lapsed
 * or online grace expired while unreachable) and the transient inconclusive /
 * unreachable outcomes only raise the banner. When neither source reports a bad
 * state the effective state is null and nothing is blocked (safe default), so
 * this middleware is safe to run fleet-wide: it only ever bites an instance whose
 * license is genuinely forged or dead.
 */
class EnforceLicense
{
    /**
     * States that HARD-lock: a forged/tampered signature or a signature-verified
     * authoritative rejection (revoked/suspended). 'expired' is deliberately NOT
     * here — an honest lapse gets a grace banner, never a brick.
     */
    public const HARD_LOCK = ['invalid', 'tampered'];

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
