@php $pct = $bucket->quotaUsedPercent(); @endphp
<x-layouts.app :title="$bucket->name">
    <x-page-header :title="$bucket->name" icon="archive"
        :subtitle="$bucket->region . ' · ' . ucfirst($bucket->access)"
        :back="['href' => route('buckets.index'), 'label' => 'Buckets']">
        <x-slot:actions>
            <x-button variant="secondary" icon="edit" href="{{ route('buckets.edit', $bucket) }}">Edit</x-button>
            <x-delete-button :name="'del-bucket'" :action="route('buckets.destroy', $bucket)"
                title="Delete Bucket?" message="Every object in this bucket will be deleted too. This cannot be undone." />
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            {{-- Upload / new folder --}}
            <x-card title="Add To Bucket">
                <form method="POST" action="{{ route('buckets.objects.store', $bucket) }}" class="space-y-4" enctype="multipart/form-data"
                    x-data="{ isFolder: false, fileName: '' }">
                    @csrf
                    <input type="hidden" name="is_folder" :value="isFolder ? 1 : 0">
                    <div class="flex items-center gap-3">
                        <button type="button" @click="isFolder = false"
                            :class="!isFolder ? 'bg-brand-50 text-brand-700 ring-brand-200' : 'text-slate-600 ring-transparent hover:bg-slate-100'"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium ring-1 ring-inset">
                            <span class="inline-flex items-center gap-1.5"><x-icon name="arrow-up" class="w-4 h-4" /> Upload Object</span>
                        </button>
                        <button type="button" @click="isFolder = true"
                            :class="isFolder ? 'bg-brand-50 text-brand-700 ring-brand-200' : 'text-slate-600 ring-transparent hover:bg-slate-100'"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium ring-1 ring-inset">
                            <span class="inline-flex items-center gap-1.5"><x-icon name="folder" class="w-4 h-4" /> New Folder</span>
                        </button>
                    </div>
                    {{-- Choosing a file pre-fills the key, so the common case is one click. --}}
                    <div x-show="!isFolder" x-cloak>
                        <x-field label="File" for="file" required :error="$errors->first('file')">
                            <input id="file" name="file" type="file"
                                @change="fileName = $event.target.files[0]?.name || '';
                                         if (fileName && !$refs.key.value) $refs.key.value = fileName;"
                                class="block w-full text-sm text-slate-600 rounded-lg ring-1 ring-inset ring-slate-200
                                       file:mr-3 file:py-2 file:px-3 file:rounded-l-lg file:border-0
                                       file:bg-brand-50 file:text-brand-700 file:font-medium hover:file:bg-brand-100" />
                        </x-field>
                    </div>
                    <x-field label="Key" for="key" required :hint="'Path within the bucket, e.g. images/logo.png'" :error="$errors->first('key')">
                        <x-input id="key" name="key" required :value="old('key')" placeholder="folder/file.txt" x-ref="key" />
                    </x-field>
                    <div class="flex justify-end">
                        <x-button type="submit" icon="plus">Add</x-button>
                    </div>
                </form>
            </x-card>

            {{-- Objects --}}
            <x-card title="Objects ({{ number_format($bucket->object_count) }})">
                @if ($objects->isEmpty())
                    <x-empty-state icon="folder" title="This Bucket Is Empty" description="Upload an object or create a folder above." />
                @else
                    <div
                        x-data="{
                            selected: [],
                            confirming: false,
                            allIds: [{{ $objects->pluck('id')->implode(',') }}],
                            toggleAll(e) { this.selected = e.target.checked ? [...this.allIds] : []; this.confirming = false; },
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
                        <form method="POST" action="{{ route('buckets.objects.bulk-destroy', $bucket) }}" x-ref="bulkForm" class="hidden">@csrf @method('DELETE')</form>

                        {{-- Bulk actions bar: appears once at least one object is selected. --}}
                        <div x-show="selected.length" x-cloak class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-50 px-4 py-2.5 ring-1 ring-inset ring-brand-200">
                            <span class="text-sm font-medium text-brand-800"><span x-text="selected.length"></span> selected</span>
                            <div class="flex items-center gap-2">
                                <template x-if="! confirming">
                                    <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="confirming = true">Delete Selected</x-button>
                                </template>
                                <template x-if="confirming">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm text-brand-800">Delete <span x-text="selected.length"></span> object(s)?</span>
                                        <x-button type="button" variant="secondary" size="sm" x-on:click="confirming = false">Cancel</x-button>
                                        <x-button type="button" variant="danger" size="sm" icon="trash" x-on:click="submitBulk()">Confirm Delete</x-button>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <x-table>
                            <thead><tr>
                                <th class="w-10">
                                    <button type="button" role="switch"
                                        :aria-checked="(allIds.length > 0 && selected.length === allIds.length).toString()"
                                        @click="selected = (allIds.length > 0 && selected.length === allIds.length) ? [] : [...allIds]"
                                        :class="(allIds.length > 0 && selected.length === allIds.length) ? 'bg-brand-600' : 'bg-slate-300'"
                                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle disabled:opacity-40"
                                        :disabled="allIds.length === 0" aria-label="Select all objects">
                                        <span :class="(allIds.length > 0 && selected.length === allIds.length) ? 'translate-x-6' : 'translate-x-1'"
                                            class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                                    </button>
                                </th>
                                <th>Key</th><th>Type</th><th>Size</th><th>Last Modified</th><th class="text-right">Actions</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($objects as $o)
                                    <tr>
                                        <td>
                                            <button type="button" role="switch"
                                                :aria-checked="selected.includes({{ $o->id }}).toString()"
                                                @click="selected.includes({{ $o->id }}) ? selected.splice(selected.indexOf({{ $o->id }}), 1) : selected.push({{ $o->id }}); confirming = false"
                                                :class="selected.includes({{ $o->id }}) ? 'bg-brand-600' : 'bg-slate-300'"
                                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors align-middle"
                                                aria-label="Select object">
                                                <span :class="selected.includes({{ $o->id }}) ? 'translate-x-6' : 'translate-x-1'"
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                                            </button>
                                        </td>
                                        <td class="font-mono text-xs">
                                            <span class="inline-flex items-center gap-1.5">
                                                <x-icon :name="$o->isFolder() ? 'folder' : 'archive'" class="w-4 h-4 text-slate-400 shrink-0" />
                                                {{ $o->key }}
                                            </span>
                                        </td>
                                        <td class="text-slate-500">{{ $o->content_type ?: ($o->isFolder() ? 'Folder' : '—') }}</td>
                                        <td class="tabular text-slate-500">{{ \App\Support\Bytes::human($o->size_bytes) }}</td>
                                        <td class="text-slate-500">{{ optional($o->last_modified)->diffForHumans() ?? '—' }}</td>
                                        <td class="text-right">
                                            @unless ($o->isFolder())
                                                <a href="{{ route('buckets.objects.download', [$bucket, $o]) }}"
                                                    class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:text-brand-800 mr-3"
                                                    title="Download {{ $o->baseName() }}">
                                                    <x-icon name="arrow-down" class="w-4 h-4" /> Download
                                                </a>
                                            @endunless
                                            <x-delete-button :name="'del-obj-' . $o->id" :action="route('buckets.objects.destroy', [$bucket, $o])"
                                                title="Delete Object?" :message="'\'' . $o->key . '\' will be permanently removed.'" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-table>
                    </div>
                @endif
            </x-card>
            @if (! $objects->isEmpty())
                <div>{{ $objects->links() }}</div>
            @endif
        </div>

        {{-- Details --}}
        <div class="space-y-6">
            <x-card title="Details">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-slate-500">Region</dt><dd class="text-slate-900">{{ $bucket->region }}</dd></div>
                    <div><dt class="text-slate-500">Access</dt><dd><x-badge :color="$bucket->isPublic() ? 'warn' : 'neutral'">{{ ucfirst($bucket->access) }}</x-badge></dd></div>
                    <div><dt class="text-slate-500">Versioning</dt><dd>@if ($bucket->versioning)<x-badge color="success">On</x-badge>@else<x-badge color="neutral">Off</x-badge>@endif</dd></div>
                    <div><dt class="text-slate-500">Objects</dt><dd class="text-slate-900 tabular">{{ number_format($bucket->object_count) }}</dd></div>
                    <div><dt class="text-slate-500">Created</dt><dd class="text-slate-900">{{ $bucket->created_at->format('M j, Y') }}</dd></div>
                </dl>
            </x-card>
            <x-card title="Storage Used">
                <div class="flex items-baseline justify-between">
                    <span class="text-2xl font-semibold tabular text-slate-900">{{ \App\Support\Bytes::human($bucket->size_bytes) }}</span>
                    @if ($bucket->quota_bytes)<span class="text-sm text-slate-500">of {{ \App\Support\Bytes::human($bucket->quota_bytes) }}</span>@endif
                </div>
                @if ($pct !== null)
                    <div class="mt-3 h-2 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full {{ $pct >= 90 ? 'bg-rose-500' : 'bg-brand-500' }}" style="width: {{ $pct }}%"></div>
                    </div>
                    <p class="mt-1.5 text-xs text-slate-500">{{ $pct }}% of quota used</p>
                @else
                    <p class="mt-1.5 text-xs text-slate-500">No quota set — unlimited.</p>
                @endif
            </x-card>
        </div>
    </div>
</x-layouts.app>
