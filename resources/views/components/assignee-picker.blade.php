@props(['users', 'selected' => [], 'name' => 'assignees'])
@php
    $selectedIds = collect(old($name, collect($selected)->map(fn ($v) => (int) $v)->all()))
        ->map(fn ($v) => (int) $v)->all();
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-wrap gap-2']) }}>
    @foreach ($users as $u)
        <label class="cursor-pointer select-none">
            <input type="checkbox" name="{{ $name }}[]" value="{{ $u->id }}" class="peer sr-only"
                @checked(in_array((int) $u->id, $selectedIds, true))>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium ring-1 ring-slate-200 text-slate-600 bg-white transition
                         peer-checked:bg-brand-600 peer-checked:text-white peer-checked:ring-brand-600
                         peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/60">
                {{ $u->name }}
            </span>
        </label>
    @endforeach
</div>
