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
        then: function (): void {
            // S3 protocol endpoint. Given a dedicated hostname it is served at
            // the root (a true drop-in endpoint); otherwise under /s3, which
            // SDKs accept as --endpoint-url https://host/s3. Registered last so
            // its greedy /{bucket}/{key} patterns cannot shadow the console.
            $domain = config('storage.s3_domain');

            if ($domain) {
                // Virtual-host style first: bucket.s3.example.com. Registered
                // ahead of the apex so a bucket subdomain is not swallowed.
                Illuminate\Support\Facades\Route::group(
                    ['domain' => '{bucket}.'.$domain],
                    fn () => require __DIR__.'/../routes/s3-vhost.php'
                );
            }

            Illuminate\Support\Facades\Route::group(array_filter([
                'domain' => $domain ?: null,
                'prefix' => $domain ? null : config('storage.s3_prefix', 's3'),
            ]), fn () => require __DIR__.'/../routes/s3.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind CloudPanel's local front nginx: trust ONLY the loopback proxy so
        // $request->ip() reflects the real client. Loopback-only is deliberate — the
        // IP-gated autofill is passwordless, and trusting all proxies would let an
        // attacker spoof X-Forwarded-For to impersonate a trusted IP.
        // Trust the local reverse proxy AND Cloudflare, so $request->ip() is the
        // real visitor rather than a Cloudflare edge address. Without the CF
        // ranges every recorded IP is Cloudflare's, which silently breaks the
        // audit log, login-attempt records, auto-ban, and any IP allowlist.
        //
        // Deliberately NOT '*': the origin is reachable directly on 80/443, so
        // trusting every proxy would let anyone spoof X-Forwarded-For by
        // skipping Cloudflare entirely. Ranges from cloudflare.com/ips-v4|v6.
        $middleware->trustProxies(at: [
            '127.0.0.1', '::1',
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ]);
        $middleware->alias([
            'api.token' => \App\Http\Middleware\AuthenticateApiToken::class,
            'security.policy' => \App\Http\Middleware\EnforceSecurityPolicy::class,
            'firewall' => \App\Http\Middleware\FirewallGuard::class,
            'license.offline' => \App\Http\Middleware\EnforceLicense::class,
            'setup' => \App\Http\Middleware\EnsureSetup::class,
            's3.auth' => \App\Http\Middleware\AuthenticateS3::class,
            's3.policy' => \App\Http\Middleware\EnforceS3Policy::class,
        ]);

        // Perimeter guard on every web request: IP bans + optional allowlist,
        // then the offline-license lockdown (no-op unless a bad .lic is present).
        $middleware->web(append: [
            \App\Http\Middleware\FirewallGuard::class,
            \App\Http\Middleware\EnforceLicense::class,
        ]);

        // First-run guard: force a fresh install through /setup until complete.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureSetup::class,
        ]);
        // Run the setup gate BEFORE auth so a brand-new install (no admin yet,
        // no session) lands on /setup instead of dead-ending at /login.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\EnsureSetup::class,
        );

        // Read-only public demo: auto-login + block writes when DEMO_MODE=true.
        $middleware->web(append: [
            \App\Http\Middleware\DemoMode::class,
        ]);
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\DemoMode::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
