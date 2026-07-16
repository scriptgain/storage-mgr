<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind CloudPanel's local front nginx: trust ONLY the loopback proxy so
        // $request->ip() reflects the real client. Loopback-only is deliberate — the
        // IP-gated autofill is passwordless, and trusting all proxies would let an
        // attacker spoof X-Forwarded-For to impersonate a trusted IP.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->alias([
            'api.token' => \App\Http\Middleware\AuthenticateApiToken::class,
            'security.policy' => \App\Http\Middleware\EnforceSecurityPolicy::class,
            'firewall' => \App\Http\Middleware\FirewallGuard::class,
            'license.offline' => \App\Http\Middleware\EnforceLicense::class,
        ]);

        // Perimeter guard on every web request: IP bans + optional allowlist,
        // then the offline-license lockdown (no-op unless a bad .lic is present).
        $middleware->web(append: [
            \App\Http\Middleware\FirewallGuard::class,
            \App\Http\Middleware\EnforceLicense::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
