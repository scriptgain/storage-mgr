<x-layouts.app :title="$policy->name">
    <x-page-header :title="$policy->name" icon="lock" :subtitle="$policy->description"
        :back="['href' => route('policies.index'), 'label' => 'Policies']">
        <x-slot:actions>
            <x-button variant="secondary" icon="edit" href="{{ route('policies.edit', $policy) }}">Edit</x-button>
            <x-delete-button :name="'del-policy'" :action="route('policies.destroy', $policy)"
                title="Delete Policy?" message="Access keys using this policy will be unassigned (not deleted)." />
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <x-card title="Document">
                <pre class="text-xs font-mono bg-slate-50 rounded-lg p-4 overflow-x-auto ring-1 ring-slate-200">{{ $policy->document }}</pre>
            </x-card>
        </div>
        <div>
            <x-card title="Attached To ({{ $policy->access_keys_count }})">
                @if ($policy->access_keys_count === 0)
                    <p class="text-sm text-slate-500">No access keys use this policy yet.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($policy->accessKeys as $k)
                            <li class="py-2.5">
                                <p class="text-sm font-medium text-slate-900">{{ $k->name }}</p>
                                <p class="text-xs text-slate-400 font-mono">{{ $k->access_key_id }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
</x-layouts.app>
