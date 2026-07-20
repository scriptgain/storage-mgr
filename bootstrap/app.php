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
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
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
