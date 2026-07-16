<x-layouts.app :title="'Edit ' . $policy->name">
    <x-page-header :title="'Edit ' . $policy->name" icon="lock"
        :back="['href' => route('policies.show', $policy), 'label' => $policy->name]" />

    <x-card>
        <form method="POST" action="{{ route('policies.update', $policy) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('policies._fields', ['policy' => $policy])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('policies.show', $policy) }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
