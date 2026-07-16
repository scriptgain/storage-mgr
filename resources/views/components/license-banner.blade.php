{{-- License lockdown banner. Renders across the authed panel whenever the
     EFFECTIVE license state (the more restrictive of the offline .lic state and
     the periodic online-validation state) is present but not 'valid'
     (expired / stale / invalid / tampered). Non-dismissible: the panel is locked
     until the license is restored. When nothing is configured the effective
     state is null and this renders nothing. --}}
@php
    $licState = null;
    $licMsg = null;
    try {
        if (auth()->check()) {
            $eff = \App\Services\OfflineLicenseVerifier::effectiveState();
            $licState = $eff['state'];
            $licMsg = $eff['message'];
        }
    } catch (\Throwable $e) {
        $licState = null;
    }
@endphp
@if ($licState && $licState !== 'valid')
    @php
        $isStale = $licState === 'stale';
        $bg    = $isStale ? 'bg-amber-50'  : 'bg-rose-50';
        $ring  = $isStale ? 'ring-amber-200' : 'ring-rose-200';
        $text  = $isStale ? 'text-amber-800' : 'text-rose-800';
        $iconc = $isStale ? 'text-amber-600' : 'text-rose-600';
        $icon  = $isStale ? 'clock' : 'lock';
        $heading = match ($licState) {
            'expired'  => 'License Expired',
            'stale'    => 'License Re-Check Required',
            'invalid'  => 'License Not Valid',
            'tampered' => 'License File Invalid',
            default    => 'License Attention Needed',
        };
    @endphp
    <div {{ $attributes->merge(['class' => "rounded-xl $bg ring-1 ring-inset $ring px-4 py-4"]) }} role="alert">
        <div class="flex items-start gap-3">
            <span class="inline-flex items-center justify-center w-9 h-9 shrink-0 rounded-lg bg-white ring-1 ring-inset {{ $ring }}">
                <x-icon :name="$icon" class="w-5 h-5 {{ $iconc }}" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold {{ $text }}">{{ $heading }}</p>
                <p class="mt-0.5 text-sm {{ $text }} opacity-90">{{ $licMsg }} Access To This Panel Is Locked Until A Valid License Is Restored.</p>
                <a href="{{ route('settings.license.edit') }}" class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold {{ $text }} hover:underline">
                    <x-icon name="shield" class="w-4 h-4" /> Go To License Settings
                </a>
            </div>
        </div>
    </div>
@endif
