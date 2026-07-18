<x-layouts.app title="Policies">
    <x-page-header title="Policies" icon="lock" subtitle="S3-style JSON policy documents attachable to access keys.">
        <x-slot:actions>
            <x-button icon="plus" href="{{ route('policies.create') }}">New Policy</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($policies->isEmpty())
        <x-card>
            <x-empty-state icon="lock" title="No Policies Yet" description="Create a policy to scope what an access key can do.">
                <x-slot:action><x-button icon="plus" href="{{ route('policies.create') }}">New Policy</x-button></x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        <div
            x-data="{
                selected: [],
                confirming: false,
                allIds: [{{ $policies->pluck('id')->implode(',') }}],
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
            <form method="POST" action="{{ route('policies.bulk-destroy') }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>

            {{-- Bulk actions bar: appears once at least one policy is selected. --}}
            <div x-show="selected.length" x-cloak class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-2.5 ring-1 ring-inset ring-brand-200">
                <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                <div class="flex items-center gap-2">
                    <template x-if="! confirming">
                        <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button>
                    </template>
                    <template x-if="confirming">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> policy(ies)?</span>
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
                                :disabled="allIds.length === 0" aria-label="Select all policies">
                                <span :class="(allIds.length > 0 && selected.length === allIds.length) ? 'translate-x-6' : 'translate-x-1'"
                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                            </button>
                        </th>
                        <th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Description</th><th>Access Keys</th><th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($policies as $p)
                        <tr>
                            <td>
                                <button type="button" role="switch"
                                    :aria-checked="selected.includes({{ $p->id }}).toString()"
                                    @click="selected.includes({{ $p->id }}) ? selected.splice(selected.indexOf({{ $p->id }}), 1) : selected.push({{ $p->id }}); confirming = false"
                                    :class="selected.includes({{ $p->id }}) ? 'bg-brand-600' : 'bg-slate-300'"
                                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle"
                                    aria-label="Select policy">
                                    <span :class="selected.includes({{ $p->id }}) ? 'translate-x-6' : 'translate-x-1'"
                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                                </button>
                            </td>
                            <td class="font-medium text-slate-900"><a href="{{ route('policies.show', $p) }}" class="hover:text-brand-700">{{ $p->name }}</a></td>
                            @if (auth()->user()->isAdmin())<td class="text-slate-500">{{ $p->owner?->name ?? 'Unassigned' }}</td>@endif
                            <td class="text-slate-500">{{ $p->description ?: '—' }}</td>
                            <td class="tabular text-slate-500">{{ $p->access_keys_count }}</td>
                            <td class="text-right">
                                <div class="inline-flex items-center gap-2">
                                    <x-icon-button :href="route('policies.show', $p)" icon="eye" title="Open" />
                                    <x-icon-button :href="route('policies.edit', $p)" icon="edit" title="Edit" />
                                    <x-delete-button :name="'del-policy-' . $p->id" :action="route('policies.destroy', $p)"
                                        title="Delete Policy?" message="Access keys using this policy will be unassigned (not deleted)." />
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
            <div class="mt-4">{{ $policies->links() }}</div>
        </div>
    @endif
</x-layouts.app>
