<x-layouts.app title="New Policy">
    <x-page-header title="New Policy" icon="lock" subtitle="Define an S3-style JSON policy document."
        :back="['href' => route('policies.index'), 'label' => 'Policies']" />

    <x-card>
        <form method="POST" action="{{ route('policies.store') }}" class="space-y-5">
            @csrf
            @include('policies._fields', ['policy' => null, 'defaultDocument' => $defaultDocument])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('policies.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Policy</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
