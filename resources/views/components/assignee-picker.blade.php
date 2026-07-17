@props(['users', 'selected' => [], 'name' => 'assignees'])
@php
    $selectedIds = collect(old($name, collect($selected)->map(fn ($v) => (int) $v)->all()))
        ->map(fn ($v) => (int) $v)->all();
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-wrap gap-x-6 gap-y-3']) }}>
    @foreach ($users as $u)
        <x-check-switch name="{{ $name }}[]" :value="$u->id" :checked="in_array((int) $u->id, $selectedIds, true)">{{ $u->name }}</x-check-switch>
    @endforeach
</div>
