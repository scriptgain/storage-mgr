<x-layouts.app title="Access Keys">
    <x-page-header title="Access Keys" icon="key" subtitle="S3-style credential pairs for programmatic access.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('access-keys.create') }}">New Access Key</x-button>
        </x-slot:actions>
    </x-page-header>

    @if (session('reveal_secret'))
        <x-alert type="warn" title="Save This Secret Key Now" class="mb-6">
            It will never be shown again.
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <code class="font-mono text-xs bg-white/70 ring-1 ring-inset ring-amber-200 rounded-lg px-3 py-2 break-all">{{ session('reveal_secret') }}</code>
            </div>
        </x-alert>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <x-stat label="Total Keys" :value="$stats['total']" icon="key" />
        <x-stat label="Active" :value="$stats['active']" icon="check-circle" />
    </div>

    @if ($accessKeys->isEmpty())
        <x-card>
            <x-empty-state icon="key" title="No Access Keys Yet" description="Create an access key pair for programmatic access.">
                <x-slot:action><x-button icon="plus" href="{{ route('access-keys.create') }}">New Access Key</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <div
            x-data="{
                selected: [],
                confirming: false,
                allIds: [{{ $accessKeys->pluck('id')->implode(',') }}],
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
            <form method="POST" action="{{ route('access-keys.bulk-destroy') }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>

            {{-- Bulk actions bar: appears once at least one access key is selected. --}}
            <div x-show="selected.length" x-cloak class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-2.5 ring-1 ring-inset ring-brand-200">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <template x-if="! confirming">
                        <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button>
                    </template>
                    <template x-if="confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> access key(s)?</span>
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
                                :disabled="allIds.length === 0" aria-label="Select all access keys">
                                <span :class="(allIds.length > 0 && selected.length === allIds.length) ? 'translate-x-6' : 'translate-x-1'"
                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                            </button>
                        </th>
                        <th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Access Key ID</th><th>Policy</th><th>Status</th><th>Last Used</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($accessKeys as $k)
                        <tr>
                            <td>
                                <button type="button" role="switch"
                                    :aria-checked="selected.includes({{ $k->id }}).toString()"
                                    @click="selected.includes({{ $k->id }}) ? selected.splice(selected.indexOf({{ $k->id }}), 1) : selected.push({{ $k->id }}); confirming = false"
                                    :class="selected.includes({{ $k->id }}) ? 'bg-brand-600' : 'bg-slate-300'"
                                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle"
                                    aria-label="Select access key">
                                    <span :class="selected.includes({{ $k->id }}) ? 'translate-x-6' : 'translate-x-1'"
                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                                </button>
                            </td>
                            <td class="font-medium text-slate-900">{{ $k->name }}</td>
                            @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $k->owner?->name ?? 'Unassigned' }}</td>@endif
                            <td class="font-mono text-xs text-slate-600">{{ $k->access_key_id }}</td>
                            <td class="text-slate-500">
                                @if ($k->policy)<a href="{{ route('policies.show', $k->policy) }}" class="text-brand-700 hover:underline">{{ $k->policy->name }}</a>@else — @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('access-keys.setstatus', $k) }}">
                                    @csrf
                                    <input type="hidden" name="status" value="{{ $k->status === 'active' ? 'disabled' : 'active' }}">
                                    <button type="submit" class="inline-flex">
                                        <x-badge :color="$k->status === 'active' ? 'success' : 'neutral'" dot>{{ $k->statusLabel() }}</x-badge>
                                    </button>
                                </form>
                            </td>
                            <td class="text-slate-500">{{ optional($k->last_used_at)->diffForHumans() ?? 'Never' }}</td>
                            <td class="text-right">
                                <x-delete-button :name="'del-key-' . $k->id" :action="route('access-keys.destroy', $k)"
                                    title="Delete Access Key?" message="Anything using this key pair will lose access immediately." />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
            <div class="mt-4">{{ $accessKeys->links() }}</div>
        </div>
    @endif
</x-layouts.app>
