<x-layouts.app title="Maintenance">
    <x-page-header title="Maintenance" icon="refresh" subtitle="Bucket housekeeping, access-key hygiene, and audit pruning windows." />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <form method="POST" action="{{ route('settings.maintenance.update') }}" class="space-y-6">
                @csrf @method('PUT')

                <x-card title="Automatic Maintenance" subtitle="A scheduled sweep that keeps buckets, keys, and logs tidy.">
                    <x-toggle name="auto_maintenance" :checked="$v['auto_maintenance'] === '1'"
                        label="Run Maintenance Automatically"
                        description="When on, the hourly sweep runs inside the window below and applies the tasks you enable." />
                </x-card>

                <x-card title="Maintenance Window" subtitle="Confine the automatic sweep to off-peak hours.">
                    <x-toggle name="maintenance_window_enabled" :checked="$v['maintenance_window_enabled'] === '1'"
                        label="Restrict To A Window"
                        description="When off, the sweep may run any hour. Manual runs always ignore the window." />

                    <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-5 border-t border-slate-100 pt-5">
                        <x-field label="Window Start" for="maintenance_window_start" :error="$errors->first('maintenance_window_start')"
                            hint="Local time in {{ config('app.timezone') }}.">
                            <x-input type="time" id="maintenance_window_start" name="maintenance_window_start" value="{{ $v['maintenance_window_start'] }}" />
                        </x-field>
                        <x-field label="Window End" for="maintenance_window_end" :error="$errors->first('maintenance_window_end')"
                            hint="Ends before it starts? The window wraps past midnight.">
                            <x-input type="time" id="maintenance_window_end" name="maintenance_window_end" value="{{ $v['maintenance_window_end'] }}" />
                        </x-field>
                    </div>

                    <div class="mt-5 border-t border-slate-100 pt-5">
                        <span class="block text-sm font-medium text-slate-700 mb-2">Days The Sweep May Run</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($days as $day)
                                <label class="cursor-pointer select-none">
                                    <input type="checkbox" name="maintenance_days[]" value="{{ $day }}"
                                        class="peer sr-only" @checked(in_array($day, $selectedDays, true))>
                                    <span class="inline-flex items-center justify-center w-14 py-1.5 rounded-lg text-sm font-medium capitalize ring-1 ring-slate-200 text-slate-600 bg-white transition
                                                 peer-checked:bg-brand-600 peer-checked:text-white peer-checked:ring-brand-600
                                                 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/60">{{ $day }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('maintenance_days.*')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                    </div>
                </x-card>

                <x-card title="Housekeeping Tasks" subtitle="What the sweep does each time it runs.">
                    <div class="space-y-5">
                        <x-toggle name="recalc_bucket_stats" :checked="$v['recalc_bucket_stats'] === '1'"
                            label="Recalculate Bucket Usage"
                            description="Recompute object counts and total size for every bucket, correcting any drift." />

                        <div class="border-t border-slate-100 pt-5">
                            <x-toggle name="disable_stale_keys" :checked="$v['disable_stale_keys'] === '1'"
                                label="Disable Stale Access Keys"
                                description="Disable access keys that were used once but have gone quiet past the window below. Never touches unused keys." />
                            <div class="mt-4 sm:max-w-xs">
                                <x-field label="Stale After (Days)" for="stale_key_days" :error="$errors->first('stale_key_days')"
                                    hint="A key is stale once it goes this long without use.">
                                    <x-input type="number" id="stale_key_days" name="stale_key_days" min="1" max="3650" value="{{ $v['stale_key_days'] }}" />
                                </x-field>
                            </div>
                        </div>

                        <div class="border-t border-slate-100 pt-5 sm:max-w-xs">
                            <x-field label="Keep Audit Log (Days)" for="audit_log_days" :error="$errors->first('audit_log_days')"
                                hint="Audit rows older than this are pruned. 0 = keep forever.">
                                <x-input type="number" id="audit_log_days" name="audit_log_days" min="0" max="3650" value="{{ $v['audit_log_days'] }}" />
                            </x-field>
                        </div>
                    </div>
                </x-card>

                <div class="flex justify-end gap-3 sticky bottom-4">
                    <div class="flex gap-3 rounded-xl bg-white/90 backdrop-blur ring-1 ring-slate-200 shadow-sm px-4 py-3">
                        <x-button variant="secondary" type="button" onclick="window.location.reload()">Reset</x-button>
                        <x-button variant="primary" type="submit" icon="check">Save Settings</x-button>
                    </div>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <x-card title="Status">
                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="flex items-center justify-between gap-4 py-2.5">
                        <dt class="text-slate-500 shrink-0">Sweep Right Now</dt>
                        <dd class="text-right">
                            @if ($allowedNow)
                                <x-badge color="success">Allowed</x-badge>
                            @else
                                <x-badge color="neutral">Outside Window</x-badge>
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 py-2.5">
                        <dt class="text-slate-500 shrink-0">Server Time</dt>
                        <dd class="font-medium text-slate-900 text-right">{{ $now->format('g:i A T') }}</dd>
                    </div>
                    @foreach ($stats as $label => $value)
                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 shrink-0">{{ $label }}</dt>
                            <dd class="font-medium text-slate-900 text-right">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <x-card title="Run Now" subtitle="Apply the enabled tasks immediately, ignoring the window.">
                <form method="POST" action="{{ route('settings.maintenance.run') }}">
                    @csrf
                    <x-button variant="secondary" type="submit" icon="refresh" class="w-full justify-center">Run Maintenance Now</x-button>
                </form>
                <p class="mt-3 text-xs text-slate-400">Save your changes first — Run Now uses the currently saved settings.</p>
            </x-card>
        </div>
    </div>
</x-layouts.app>
