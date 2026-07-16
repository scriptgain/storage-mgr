<x-layouts.app :title="'Edit ' . $bucket->name">
    <x-page-header :title="'Edit ' . $bucket->name" icon="archive"
        :back="['href' => route('buckets.show', $bucket), 'label' => $bucket->name]" />

    <x-card>
        <form method="POST" action="{{ route('buckets.update', $bucket) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('buckets._fields', ['bucket' => $bucket])
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('buckets.show', $bucket) }}">Cancel</x-button>
                <x-button type="submit" icon="check">Save Changes</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
