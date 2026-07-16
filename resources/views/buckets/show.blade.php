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
                <form method="POST" action="{{ route('buckets.objects.store', $bucket) }}" class="space-y-4" x-data="{ isFolder: false }">
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
                    <x-field label="Key" for="key" required :hint="'Path within the bucket, e.g. images/logo.png'" :error="$errors->first('key')">
                        <x-input id="key" name="key" required :value="old('key')" placeholder="folder/file.txt" />
                    </x-field>
                    <div x-show="!isFolder" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-field label="Content Type" for="content_type" :error="$errors->first('content_type')">
                            <x-input id="content_type" name="content_type" :value="old('content_type')" placeholder="text/plain" />
                        </x-field>
                        <x-field label="Size (bytes)" for="size_bytes" :error="$errors->first('size_bytes')">
                            <x-input id="size_bytes" name="size_bytes" type="number" min="0" :value="old('size_bytes')" placeholder="0" />
                        </x-field>
                    </div>
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
                    <x-table>
                        <thead><tr><th>Key</th><th>Type</th><th>Size</th><th>Last Modified</th><th class="text-right">Actions</th></tr></thead>
                        <tbody>
                            @foreach ($objects as $o)
                                <tr>
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
                                        <x-delete-button :name="'del-obj-' . $o->id" :action="route('buckets.objects.destroy', [$bucket, $o])"
                                            title="Delete Object?" :message="'\'' . $o->key . '\' will be permanently removed.'" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-table>
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
