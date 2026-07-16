<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>License Key Invalid &middot; {{ config('brand.name') }}</title>
    <x-tailwind-cdn />
    <x-accent-style />
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="h-full bg-slate-50">
@php
    $state = $lock['state'] ?? 'invalid';
    $reason = $lock['reason'] ?? null;
    $message = $lock['message'] ?: 'This Instance Could Not Be Verified As Licensed.';
    // Specific, human label for the rejection (the page title stays "License Key Invalid").
    $reasonLabel = match (true) {
        $reason === 'revoked' => 'Revoked',
        $reason === 'suspended' => 'Suspended',
        $reason === 'not_found' => 'Not Found',
        $reason === 'activation_limit' => 'Seat Limit Exceeded',
        $reason === 'domain_limit' => 'Domain Limit Exceeded',
        $state === 'expired' => 'Expired',
        $state === 'tampered' => 'License File Invalid',
        default => 'Invalid',
    };
@endphp
<div class="min-h-full flex flex-col lg:flex-row">

    {{-- Brand panel --}}
    <div class="lg:w-1/2 bg-chrome text-white px-8 py-12 lg:p-16 flex flex-col justify-between">
        <x-brand class="text-white" />
        <div class="hidden lg:block max-w-md">
            <div class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-rose-200 ring-1 ring-inset ring-white/15">
                <x-icon name="lock" class="w-4 h-4" /> Management Locked
            </div>
            <h2 class="mt-6 text-3xl font-semibold tracking-tight">This Instance Is Locked.</h2>
            <p class="mt-4 text-slate-300 leading-relaxed">
                {{ config('brand.name') }} Could Not Confirm A Valid License For This
                Installation, So Management Has Been Suspended. Re-Sync To Pick Up A
                Renewed License, Or Enter A New License Key To Restore Access.
            </p>
        </div>
        <p class="text-xs text-slate-400">{{ config('brand.name') }} &middot; {{ config('brand.tagline') }}</p>
    </div>

    {{-- Recovery panel --}}
    <div class="lg:w-1/2 flex items-center justify-center px-6 py-12">
        <div class="w-full max-w-md rounded-2xl bg-white ring-1 ring-slate-200 shadow-xl p-8 sm:p-10">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-11 h-11 shrink-0 rounded-xl bg-rose-50 ring-1 ring-inset ring-rose-200">
                    <x-icon name="lock" class="w-6 h-6 text-rose-600" />
                </span>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">License Key Invalid</h1>
                    <span class="mt-1 inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-700">{{ $reasonLabel }}</span>
                </div>
            </div>

            <p class="mt-4 text-sm text-slate-600 leading-relaxed">{{ $message }}</p>
            <p class="mt-2 text-sm text-slate-500">You Cannot Manage This Instance Until A Valid License Is Restored.</p>

            @if (session('warning'))
                <div class="mt-5"><x-alert type="danger">{{ session('warning') }}</x-alert></div>
            @endif
            @if (session('status'))
                <div class="mt-5"><x-alert type="success">{{ session('status') }}</x-alert></div>
            @endif
            @if ($errors->any())
                <div class="mt-5"><x-alert type="danger">{{ $errors->first() }}</x-alert></div>
            @endif

            {{-- Re-Sync: re-validate online in case the license was renewed / reactivated upstream. --}}
            <form method="POST" action="{{ route('license.resync') }}" class="mt-6" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <button type="submit" x-bind:disabled="busy"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-60 disabled:cursor-not-allowed px-4 py-2.5 text-sm font-semibold shadow-sm transition">
                    <svg x-show="busy" x-cloak class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0a12 12 0 00-8 12h4z"></path>
                    </svg>
                    <x-icon name="refresh" class="w-4 h-4" x-show="!busy" />
                    <span x-text="busy ? 'Re-Syncing...' : 'Re-Sync License'">Re-Sync License</span>
                </button>
                <p class="mt-2 text-center text-xs text-slate-400">Checks {{ config('brand.name') }} Licensing For A Renewed Or Reactivated License.</p>
            </form>

            <div class="my-6 flex items-center gap-3 text-xs text-slate-400">
                <span class="h-px flex-1 bg-slate-200"></span> Or Enter A New Key <span class="h-px flex-1 bg-slate-200"></span>
            </div>

            {{-- Enter a new key. --}}
            <form method="POST" action="{{ route('license.rekey') }}" class="space-y-4" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <x-field label="License Key" for="license_key" required>
                    <x-input id="license_key" name="license_key" :value="old('license_key')" required autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX" />
                </x-field>
                <button type="submit" x-bind:disabled="busy"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 disabled:opacity-60 disabled:cursor-not-allowed px-4 py-2.5 text-sm font-semibold shadow-sm transition">
                    <svg x-show="busy" x-cloak class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0a12 12 0 00-8 12h4z"></path>
                    </svg>
                    <x-icon name="key" class="w-4 h-4" x-show="!busy" />
                    <span x-text="busy ? 'Validating...' : 'Save &amp; Activate Key'">Save &amp; Activate Key</span>
                </button>
            </form>

            <div class="mt-8 border-t border-slate-100 pt-4 text-center">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-xs font-medium text-slate-400 hover:text-slate-600">Sign Out</button>
                </form>
            </div>
        </div>
    </div>

</div>
</body>
</html>
