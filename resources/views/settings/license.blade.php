@php
    $statusMap = [
        'valid' => ['success', 'Valid'],
        'unverified' => ['warn', 'Not Yet Verified'],
        'grace' => ['warn', 'Grace Period'],
        'invalid' => ['danger', 'Invalid'],
        'unlicensed' => ['neutral', 'No Key'],
    ];
    [$statusColor, $statusLabel] = $statusMap[$license['status']] ?? ['neutral', ucfirst($license['status'] ?? 'Unknown')];
    $masked = $license['key'] ? \Illuminate\Support\Str::mask($license['key'], '*', 4, max(0, strlen($license['key']) - 8)) : null;

    // Offline .lic state presentation.
    $offState = $offline['state'] ?? null;
    $offMap = [
        'valid' => ['success', 'Verified', 'check-circle'],
        'expired' => ['danger', 'Expired', 'warning'],
        'stale' => ['warn', 'Re-Check Required', 'clock'],
        'invalid' => ['danger', 'Not Valid', 'x-circle'],
        'tampered' => ['danger', 'Signature Invalid', 'lock'],
    ];
    [$offColor, $offLabel, $offIcon] = $offMap[$offState] ?? ['neutral', 'No File', 'shield'];

    // Online validation state presentation.
    $onState = $online['state'] ?? null;
    $onMap = [
        'valid' => ['success', 'Validated', 'check-circle'],
        'expired' => ['danger', 'Expired', 'warning'],
        'stale' => ['warn', 'Cannot Confirm', 'clock'],
        'invalid' => ['danger', 'Not Valid', 'x-circle'],
    ];
    [$onColor, $onLabel, $onIcon] = $onMap[$onState] ?? ['neutral', $online['configured'] ? 'Not Yet Checked' : 'No Key', 'sync'];
    $onSeats = null;
    if (! empty($online['seats'])) {
        $decoded = json_decode((string) $online['seats'], true);
        if (is_array($decoded) && isset($decoded['max'])) {
            $onSeats = ($decoded['used'] ?? '?').' / '.($decoded['max'] ?? '?');
        }
    }
    $fmt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->toDayDateTimeString() : null;
    $ago = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->diffForHumans() : null;
@endphp
<x-layouts.app title="License">
    <x-page-header title="License" icon="shield" subtitle="Manage your {{ $license['product'] }} license key and signed license file.">
        <x-slot:actions>
            <x-button variant="secondary" icon="settings" href="{{ route('settings.index') }}">Settings</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($offline['present'] && $offState && $offState !== 'valid')
        <div class="mb-6">
            <x-alert type="danger" title="License {{ $offLabel }}">
                {{ $offline['message'] }} This Instance Is Locked Until A Valid License File Is Uploaded Below.
            </x-alert>
        </div>
    @endif

    @if ($onState && $onState !== 'valid')
        <div class="mb-6">
            <x-alert type="{{ $onState === 'stale' ? 'warning' : 'danger' }}" title="Online Validation {{ $onLabel }}">
                {{ $online['message'] }} This Instance Is Locked Until The License Is Restored.
            </x-alert>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-card title="Signed License File">
                <p class="text-sm text-slate-600">Upload the signed <code class="font-mono text-xs">.lic</code> file issued by ScriptGain. It is verified offline against ScriptGain's public key: no network connection is needed, and a tampered or expired file is rejected.</p>
                <form method="POST" action="{{ route('settings.license.upload') }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                    @csrf
                    <x-field label="License File" for="license_file" hint="Select the .lic file downloaded from your ScriptGain account." :error="$errors->first('license_file')">
                        <input id="license_file" name="license_file" type="file" accept=".lic,application/json,text/plain"
                               class="block w-full text-sm text-slate-700 rounded-lg ring-1 ring-inset ring-slate-300 bg-white file:mr-3 file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100 cursor-pointer">
                    </x-field>
                    <div class="flex items-center gap-2">
                        <x-button type="submit" icon="check">Upload & Verify</x-button>
                    </div>
                </form>
                @if ($offline['present'])
                    <div class="mt-4 pt-4 border-t border-slate-100 flex items-center justify-between gap-3">
                        <p class="text-sm text-slate-500">A license file is currently installed.</p>
                        <form method="POST" action="{{ route('settings.license.file.remove') }}">
                            @csrf
                            @method('DELETE')
                            <x-button type="submit" variant="ghost" size="sm" icon="trash">Remove File</x-button>
                        </form>
                    </div>
                @endif
            </x-card>

            <x-card title="License Key">
                <form method="POST" action="{{ route('settings.license.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <x-field label="Key" for="license_key" hint="Paste the key issued by ScriptGain, for example BKM-XXXX-XXXX-XXXX. Leave blank to remove it." :error="$errors->first('license_key')">
                        <x-input id="license_key" name="license_key" :value="old('license_key', $license['key'])" placeholder="BKM-XXXX-XXXX-XXXX" autocomplete="off" />
                    </x-field>
                    <div class="flex items-center gap-2">
                        <x-button type="submit" icon="check">Save Key</x-button>
                    </div>
                </form>
            </x-card>

            <x-card title="Online Validation">
                <p class="text-sm text-slate-600">Your license key is validated against ScriptGain automatically every {{ (int) config('licensing.online_check_interval_days', 2) }} days. Each response is cryptographically signed and verified locally. You can validate right now:</p>
                <form method="POST" action="{{ route('settings.license.check') }}" class="mt-4">
                    @csrf
                    <x-button type="submit" variant="secondary" icon="sync">Check License Now</x-button>
                </form>
                <dl class="mt-4 pt-4 border-t border-slate-100 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Last Checked</dt><dd class="text-slate-700 text-right">{{ $ago($online['checked_at']) ?? 'Never' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Last Confirmed</dt><dd class="text-slate-700 text-right">{{ $ago($online['last_success_at']) ?? 'Never' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Next Check Due</dt><dd class="text-slate-700 text-right">{{ $fmt($online['next_due_at']) ?? '—' }}</dd></div>
                    @if ($online['last_error'])
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Last Error</dt><dd class="text-amber-700 text-right">{{ \Illuminate\Support\Str::limit($online['last_error'], 80) }}</dd></div>
                    @endif
                </dl>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Online License Status">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">State</dt><dd><x-badge :color="$onColor" dot>{{ $onLabel }}</x-badge></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Product</dt><dd class="font-medium text-slate-900">{{ $online['product'] ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Expires</dt><dd class="text-slate-700 text-right">{{ $fmt($online['expires_at']) ?: ($online['state'] === 'valid' ? 'Perpetual' : '—') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Seats</dt><dd class="text-slate-700">{{ $onSeats ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Last Confirmed</dt><dd class="text-slate-700">{{ $ago($online['last_success_at']) ?? 'Never' }}</dd></div>
                </dl>
            </x-card>

            <x-card title="License File Status">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">State</dt><dd><x-badge :color="$offColor" dot>{{ $offLabel }}</x-badge></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Product</dt><dd class="font-medium text-slate-900">{{ $offline['product'] ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Type</dt><dd class="font-medium text-slate-900">{{ $offline['type'] ? ucfirst($offline['type']) : '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Expires</dt><dd class="text-slate-700 text-right">{{ $fmt($offline['expires_at']) ?: 'Perpetual' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Offline Re-Check By</dt><dd class="text-slate-700 text-right">{{ $fmt($offline['offline_expires_at']) ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Last Checked</dt><dd class="text-slate-700">{{ $offline['checked_at'] ? \Illuminate\Support\Carbon::parse($offline['checked_at'])->diffForHumans() : 'Never' }}</dd></div>
                </dl>
            </x-card>

            <x-card title="License Key Status">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Status</dt><dd><x-badge :color="$statusColor" dot>{{ $statusLabel }}</x-badge></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Product</dt><dd class="font-medium text-slate-900">{{ $license['product'] }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Plan</dt><dd class="font-medium text-slate-900">{{ $license['plan'] ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Key</dt><dd class="font-mono text-xs text-slate-700">{{ $masked ?: '—' }}</dd></div>
                </dl>
            </x-card>

            <x-card title="Need A Key?">
                <p class="text-sm text-slate-600">Purchase or manage your subscription at <a href="https://scriptgain.com/products/backup-manager" target="_blank" rel="noopener" class="text-brand-700 hover:text-brand-800 font-medium">scriptgain.com</a>.</p>
            </x-card>
        </div>
    </div>
</x-layouts.app>
