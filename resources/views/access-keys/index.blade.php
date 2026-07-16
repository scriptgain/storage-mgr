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
        <x-table>
            <thead>
                <tr><th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Access Key ID</th><th>Policy</th><th>Status</th><th>Last Used</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
                @foreach ($accessKeys as $k)
                    <tr>
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
    @endif
</x-layouts.app>
