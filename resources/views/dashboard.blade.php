<x-layouts.app title="Dashboard">
    <x-page-header title="Dashboard" subtitle="Object storage at a glance.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" icon="key" href="{{ route('access-keys.index') }}">Access Keys</x-button>
            <x-button size="sm" icon="plus" href="{{ route('buckets.create') }}">New Bucket</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-stat label="Total Buckets" :value="number_format($stats['buckets'])" icon="archive" />
        <x-stat label="Total Objects" :value="number_format($stats['objects'])" icon="folder" />
        <x-stat label="Storage Used" :value="$stats['storage_used']" icon="database" />
        <x-stat label="Active Access Keys" :value="number_format($stats['active_keys'])" icon="key" />
    </div>

    <div class="mt-6">
        <x-card title="Recent Buckets">
            @if ($recent->isEmpty())
                <x-empty-state icon="archive" title="No Buckets Yet" description="Create your first bucket to start storing objects.">
                    <x-slot:action><x-button icon="plus" href="{{ route('buckets.create') }}">New Bucket</x-button></x-slot:action>
                </x-empty-state>
            @else
                <x-table>
                    <thead><tr><th>Name</th><th>Region</th><th>Access</th><th>Objects</th><th>Size</th></tr></thead>
                    <tbody>
                        @foreach ($recent as $b)
                            <tr>
                                <td class="font-medium text-slate-900"><a href="{{ route('buckets.show', $b) }}" class="hover:text-brand-700">{{ $b->name }}</a></td>
                                <td class="text-slate-500">{{ $b->region }}</td>
                                <td><x-badge :color="$b->isPublic() ? 'warn' : 'neutral'">{{ ucfirst($b->access) }}</x-badge></td>
                                <td class="tabular text-slate-500">{{ number_format($b->object_count) }}</td>
                                <td class="tabular text-slate-500">{{ \App\Support\Bytes::human($b->size_bytes) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-table>
            @endif
        </x-card>
    </div>
</x-layouts.app>
