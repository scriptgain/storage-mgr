@php
    use Illuminate\Support\Str;
    use App\Support\Bytes;

    $privateBuckets = $stats['buckets'] - $publicBuckets;
    $disabledKeys = $totalKeys - $activeKeys;

    // KPI row — icon chip + number + label + a meaningful one-line subtext.
    $kpis = [
        ['label' => 'Buckets', 'value' => number_format($stats['buckets']), 'icon' => 'archive',
            'sub' => $publicBuckets ? $publicBuckets . ' public · ' . $privateBuckets . ' private' : 'All private',
            'tone' => $publicBuckets ? 'amber' : 'muted'],
        ['label' => 'Objects', 'value' => number_format($stats['objects']), 'icon' => 'folder',
            'sub' => 'Stored across all buckets', 'tone' => 'muted'],
        ['label' => 'Storage Used', 'value' => $stats['storage_used'], 'icon' => 'database',
            'sub' => $capacity['total'] ? 'of ' . Bytes::human($capacity['total']) . ' provisioned' : 'No quotas set',
            'tone' => 'muted'],
        ['label' => 'Access Keys', 'value' => number_format($activeKeys), 'icon' => 'key',
            'sub' => $disabledKeys ? $disabledKeys . ' ' . Str::plural('key', $disabledKeys) . ' disabled' : 'All keys active',
            'tone' => $disabledKeys ? 'amber' : 'emerald'],
    ];
    $toneClass = ['muted' => 'text-slate-400', 'amber' => 'text-amber-600', 'emerald' => 'text-emerald-600'];

    // Capacity gauge (semicircle) geometry.
    $gaugeLen = 276.46; // ~ pi * r, r = 88
    $capTotal = $capacity['total'];
    if ($capTotal > 0) {
        $capPctRaw = $capacity['used'] / $capTotal * 100;
        $capPct = $capPctRaw >= 1 ? (int) round($capPctRaw) : round($capPctRaw, $capPctRaw >= 0.1 ? 1 : 2);
        $capOver = $capPctRaw > 90;
        // Keep a hairline of arc visible even for near-empty capacity.
        $capDash = round(max(1.5, min(100, $capPctRaw) / 100 * $gaugeLen), 1);
    }

    $maxBucket = max(1, ($byBucket->max('size_bytes') ?: 1));
@endphp

<x-layouts.app title="Dashboard">
    {{-- Brand accent bound to the runtime --color-brand-* var. --}}
    <style>
        .bk-ok-fill { fill: var(--color-brand-500); }
        .bk-ok-stroke { stroke: var(--color-brand-500); }
        .bk-ok-bg { background-color: var(--color-brand-500); }
    </style>

    <x-page-header title="Dashboard" subtitle="Object storage at a glance.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" icon="key" href="{{ route('access-keys.index') }}">Access Keys</x-button>
            <x-button size="sm" icon="plus" href="{{ route('buckets.create') }}">New Bucket</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- KPI row --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ($kpis as $k)
            <div class="group relative flex flex-col overflow-hidden rounded-xl bg-white ring-1 ring-slate-200 shadow-sm transition hover:shadow-md hover:ring-brand-200">
                <span class="h-1 w-full bg-gradient-to-r from-brand-400 to-brand-600"></span>
                <div class="flex flex-1 items-center gap-4 p-5">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100">
                        <x-icon :name="$k['icon']" class="h-5 w-5" />
                    </span>
                    <div class="ml-auto text-right">
                        <div class="text-2xl font-semibold tracking-tight text-slate-900 tabular">{{ $k['value'] }}</div>
                        <div class="text-sm font-medium text-slate-600">{{ $k['label'] }}</div>
                        <div class="mt-0.5 text-xs font-medium {{ $toneClass[$k['tone']] }}">{{ $k['sub'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Storage distribution + capacity --}}
    <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
        {{-- Storage by bucket (signature visual) --}}
        <x-card title="Storage by Bucket" subtitle="Largest buckets by size on disk" class="lg:col-span-2">
            <x-slot:actions>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700 ring-1 ring-inset ring-brand-200">
                    <x-icon name="database" class="h-3.5 w-3.5" /> {{ $stats['storage_used'] }} total
                </span>
            </x-slot:actions>

            @if ($byBucket->isEmpty())
                <div class="flex h-40 flex-col items-center justify-center text-center">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-400"><x-icon name="archive" class="h-5 w-5" /></span>
                    <p class="mt-3 text-sm text-slate-500">No buckets yet.</p>
                </div>
            @else
                <div class="space-y-3.5">
                    @foreach ($byBucket as $b)
                        @php $pct = round($b->size_bytes / $maxBucket * 100, 1); @endphp
                        <div>
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <a href="{{ route('buckets.show', $b) }}" class="min-w-0 truncate font-medium text-slate-900 hover:text-brand-700">{{ $b->name }}</a>
                                <span class="shrink-0 tabular text-slate-600">{{ Bytes::human($b->size_bytes) }}</span>
                            </div>
                            <div class="mt-1.5 flex items-center gap-2.5">
                                <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bk-ok-bg" style="width: {{ max(2, $pct) }}%"></div>
                                </div>
                                <span class="w-24 shrink-0 text-right text-xs text-slate-400 tabular">{{ number_format($b->object_count) }} {{ Str::plural('obj', $b->object_count) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <x-slot:footer>
                <div class="flex items-center gap-6">
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white text-slate-400 ring-1 ring-slate-200"><x-icon name="folder" class="h-4 w-4" /></span>
                        <div>
                            <p class="text-lg font-semibold leading-tight tabular text-slate-900">{{ number_format($stats['objects']) }}</p>
                            <p class="text-xs text-slate-500">Objects stored</p>
                        </div>
                    </div>
                    <span class="h-9 w-px bg-slate-200"></span>
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg {{ $publicBuckets ? 'bg-amber-50 text-amber-600 ring-1 ring-amber-100' : 'bg-white text-slate-400 ring-1 ring-slate-200' }}"><x-icon name="globe" class="h-4 w-4" /></span>
                        <div>
                            <p class="text-lg font-semibold leading-tight tabular {{ $publicBuckets ? 'text-amber-600' : 'text-slate-900' }}">{{ $publicBuckets }}</p>
                            <p class="text-xs text-slate-500">Public buckets</p>
                        </div>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>

        {{-- Provisioned capacity gauge --}}
        <x-card title="Provisioned Capacity" subtitle="Used against bucket quotas">
            @if ($capTotal > 0)
                <div>
                    <div class="mx-auto w-full max-w-[240px]">
                        <svg viewBox="0 0 200 122" width="100%" role="img" aria-label="Capacity {{ $capPct }} percent used">
                            <path d="M12 110 A88 88 0 0 1 188 110" fill="none" stroke="#e2e8f0" stroke-width="14" stroke-linecap="round" />
                            <path d="M12 110 A88 88 0 0 1 188 110" fill="none" stroke-width="14" stroke-linecap="round"
                                stroke-dasharray="{{ $capDash }} 1000"
                                @class(['bk-ok-stroke' => ! $capOver]) @style(['stroke:#f43f5e' => $capOver]) />
                            <text x="100" y="92" text-anchor="middle" fill="#0f172a" style="font-size:34px;font-weight:700;font-variant-numeric:tabular-nums">{{ $capPct }}%</text>
                            <text x="100" y="110" text-anchor="middle" fill="#94a3b8" style="font-size:11px;letter-spacing:.02em">used</text>
                        </svg>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-lg font-semibold text-slate-900 tabular">{{ Bytes::human($capacity['used']) }}</span>
                        <span class="text-sm text-slate-500 tabular">of {{ Bytes::human($capTotal) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">
                        {{ Bytes::human(max(0, $capTotal - $capacity['used'])) }} free
                        @if ($capacity['unlimited']) · {{ $capacity['unlimited'] }} unlimited {{ Str::plural('bucket', $capacity['unlimited']) }} @endif
                    </p>
                </div>
            @else
                <div class="flex flex-col items-center justify-center py-6 text-center">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-400"><x-icon name="database" class="h-5 w-5" /></span>
                    <p class="mt-3 text-sm text-slate-500">No bucket quotas configured.</p>
                    <a href="{{ route('buckets.index') }}" class="mt-1 text-sm font-medium text-brand-700 hover:underline">Manage buckets</a>
                </div>
            @endif
        </x-card>
    </div>

    {{-- Recent buckets --}}
    <div class="mt-6">
        <x-card title="Recent Buckets" subtitle="Newest buckets in this tenant" :flush="$recent->isNotEmpty()">
            <x-slot:actions>
                <x-button variant="ghost" size="sm" href="{{ route('buckets.index') }}">View All</x-button>
            </x-slot:actions>

            @if ($recent->isEmpty())
                <x-empty-state icon="archive" title="No Buckets Yet" description="Create your first bucket to start storing objects.">
                    <x-slot:action><x-button icon="plus" href="{{ route('buckets.create') }}">New Bucket</x-button></x-slot:action>
                </x-empty-state>
            @else
                <x-table flush>
                    <thead><tr><th>Bucket</th><th>Access</th><th class="text-right">Objects</th><th class="text-right">Size</th></tr></thead>
                    <tbody>
                        @foreach ($recent as $b)
                            <tr class="cursor-pointer" onclick="window.location='{{ route('buckets.show', $b) }}'">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100"><x-icon name="archive" class="h-4 w-4" /></span>
                                        <div class="min-w-0">
                                            <div class="font-medium text-slate-900 truncate">{{ $b->name }}</div>
                                            <div class="text-xs text-slate-500 truncate">{{ $b->region }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td><x-badge :color="$b->isPublic() ? 'warn' : 'neutral'" dot>{{ ucfirst($b->access) }}</x-badge></td>
                                <td class="text-right tabular text-slate-600">{{ number_format($b->object_count) }}</td>
                                <td class="text-right tabular text-slate-600">{{ Bytes::human($b->size_bytes) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-table>
            @endif
        </x-card>
    </div>
</x-layouts.app>
