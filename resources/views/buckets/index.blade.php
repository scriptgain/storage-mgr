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
        <div
            x-data="{
                selected: [],
                confirming: false,
                allIds: [{{ $buckets->pluck('id')->implode(',') }}],
                submitBulk() {
                    const f = this.$refs.bulkForm;
                    f.querySelectorAll('input.js-dyn').forEach(n => n.remove());
                    this.selected.forEach(id => {
                        const i = document.createElement('input');
                        i.type = 'hidden'; i.name = 'ids[]'; i.value = id; i.className = 'js-dyn';
                        f.appendChild(i);
                    });
                    f.submit();
                }
            }">
            {{-- Hidden form the bulk delete posts through. --}}
            <form method="POST" action="{{ route('buckets.bulk-destroy') }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>

            {{-- Bulk actions bar: appears once at least one bucket is selected. --}}
            <div x-show="selected.length" x-cloak class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-2.5 ring-1 ring-inset ring-brand-200">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <template x-if="! confirming">
                        <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button>
                    </template>
                    <template x-if="confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> bucket(s)?</span>
                            <x-button type="button" variant="secondary" size="sm" x-on:click="confirming = false">Cancel</x-button>
                            <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="submitBulk()">Confirm Delete</x-button>
                        </div>
                    </template>
                </div>
            </div>

            <x-table>
                <thead>
                    <tr>
                        <th class="w-10">
                            <button type="button" role="switch"
                                :aria-checked="(allIds.length > 0 && selected.length === allIds.length).toString()"
                                @click="selected = (allIds.length > 0 && selected.length === allIds.length) ? [] : [...allIds]"
                                :class="(allIds.length > 0 && selected.length === allIds.length) ? 'bg-brand-600' : 'bg-slate-300'"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle disabled:opacity-40"
                                :disabled="allIds.length === 0" aria-label="Select all buckets">
                                <span :class="(allIds.length > 0 && selected.length === allIds.length) ? 'translate-x-6' : 'translate-x-1'"
                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                            </button>
                        </th>
                        <th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Region</th><th>Access</th><th>Versioning</th><th>Objects</th><th>Size</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($buckets as $b)
                        <tr>
                            <td>
                                <button type="button" role="switch"
                                    :aria-checked="selected.includes({{ $b->id }}).toString()"
                                    @click="selected.includes({{ $b->id }}) ? selected.splice(selected.indexOf({{ $b->id }}), 1) : selected.push({{ $b->id }}); confirming = false"
                                    :class="selected.includes({{ $b->id }}) ? 'bg-brand-600' : 'bg-slate-300'"
                                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle"
                                    aria-label="Select bucket">
                                    <span :class="selected.includes({{ $b->id }}) ? 'translate-x-6' : 'translate-x-1'"
                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                                </button>
                            </td>
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
        </div>
    @endif
</x-layouts.app>
