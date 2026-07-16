@php $p = $policy; @endphp
<x-field label="Name" for="name" required :error="$errors->first('name')">
    <x-input id="name" name="name" required :value="old('name', $p->name ?? '')" placeholder="ReadOnlyAccess" />
</x-field>
<x-field label="Description" for="description" :error="$errors->first('description')">
    <x-input id="description" name="description" :value="old('description', $p->description ?? '')" placeholder="What this policy allows." />
</x-field>
<x-field label="Document (JSON)" for="document" required hint="An S3-style policy document." :error="$errors->first('document')">
    <textarea id="document" name="document" rows="12" required
        class="block w-full rounded-lg border-0 bg-white px-3 py-2 font-mono text-xs text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-500">{{ old('document', $p->document ?? $defaultDocument) }}</textarea>
</x-field>
@isset($owners)
    @if ($owners->isNotEmpty())
        <x-field label="Owner" for="owner_id" hint="User who owns this policy." :error="$errors->first('owner_id')">
            <x-select id="owner_id" name="owner_id">
                <option value="">{{ auth()->user()->name }} (me)</option>
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected(old('owner_id', $p->user_id ?? '') == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                @endforeach
            </x-select>
        </x-field>
        <x-field label="Also Visible To" hint="Extra users who can see this policy. Leave empty for the owner and admins only.">
            <x-assignee-picker :users="$owners" :selected="$p?->assignees?->pluck('id')->all() ?? []" />
        </x-field>
    @endif
@endisset
