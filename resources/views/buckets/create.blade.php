<x-layouts.app title="New Bucket">
    <x-page-header title="New Bucket" icon="archive" subtitle="Create a bucket to store objects."
        :back="['href' => route('buckets.index'), 'label' => 'Buckets']" />

    <x-card>
        <form method="POST" action="{{ route('buckets.store') }}" class="space-y-5">
            @csrf
            @include('buckets._fields', ['bucket' => null])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('buckets.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Bucket</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
