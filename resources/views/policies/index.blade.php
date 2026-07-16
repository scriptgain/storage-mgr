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
        <x-table>
            <thead>
                <tr><th>Name</th>@if (auth()->user()->isAdmin())<th>Owner</th>@endif<th>Description</th><th>Access Keys</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
                @foreach ($policies as $p)
                    <tr>
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
    @endif
</x-layouts.app>
