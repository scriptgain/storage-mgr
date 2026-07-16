<x-layouts.app title="Buckets">
    <x-page-header title="Buckets" icon="archive" subtitle="Object storage buckets and their access settings.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('buckets.create') }}">New Bucket</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat label="Total Buckets" :value="$stats['total']" icon="archive" />
        <x-stat label="Public Buckets" :value="$stats['public']" icon="eye" />
        <x-stat label="Total Objects" :value="number_format($stats['objects'])" icon="folder" />
    </div>

    @if ($buckets->isEmpty())
        <x-card>
            <x-empty-state icon="archive" title="No Buckets Yet" description="Create a bucket to start storing objects.">
                <x-slot:action><x-button icon="plus" href="{{ route('buckets.create') }}">New Bucket</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <x-table>
            <thead>
                <tr><th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Region</th><th>Access</th><th>Versioning</th><th>Objects</th><th>Size</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
                @foreach ($buckets as $b)
                    <tr>
                        <td class="font-medium text-slate-900"><a href="{{ route('buckets.show', $b) }}" class="hover:text-brand-700">{{ $b->name }}</a></td>
                        @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $b->owner?->name ?? 'Unassigned' }}</td>@endif
                        <td class="text-slate-500">{{ $b->region }}</td>
                        <td><x-badge :color="$b->isPublic() ? 'warn' : 'neutral'">{{ ucfirst($b->access) }}</x-badge></td>
                        <td>@if ($b->versioning)<x-badge color="success">On</x-badge>@else<x-badge color="neutral">Off</x-badge>@endif</td>
                        <td class="tabular text-slate-500">{{ number_format($b->object_count) }}</td>
                        <td class="tabular text-slate-500">{{ \App\Support\Bytes::human($b->size_bytes) }}</td>
                        <td class="text-right">
                            <div class="inline-flex items-center gap-2">
                                <x-icon-button :href="route('buckets.show', $b)" icon="eye" title="Open" />
                                <x-icon-button :href="route('buckets.edit', $b)" icon="edit" title="Edit" />
                                <x-delete-button :name="'del-bucket-' . $b->id" :action="route('buckets.destroy', $b)"
                                    title="Delete Bucket?" message="Every object in this bucket will be deleted too. This cannot be undone." />
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>
        <div class="mt-4">{{ $buckets->links() }}</div>
    @endif
</x-layouts.app>
