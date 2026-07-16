@php $b = $bucket; @endphp
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <x-field label="Bucket Name" for="name" required hint="Lowercase letters, numbers, dots, and hyphens only." :error="$errors->first('name')">
        <x-input id="name" name="name" required :value="old('name', $b->name ?? '')" placeholder="my-app-uploads" />
    </x-field>
    <x-field label="Region" for="region" required :error="$errors->first('region')">
        <x-input id="region" name="region" required :value="old('region', $b->region ?? 'us-east-1')" />
    </x-field>
    <x-field label="Access" for="access" required :error="$errors->first('access')">
        <x-select id="access" name="access" required>
            @foreach (\App\Models\Bucket::ACCESS_LEVELS as $val => $label)
                <option value="{{ $val }}" @selected(old('access', $b->access ?? 'private') === $val)>{{ $label }}</option>
            @endforeach
        </x-select>
    </x-field>
    <x-field label="Quota" for="quota_bytes" hint="Bytes. Leave blank for unlimited." :error="$errors->first('quota_bytes')">
        <x-input id="quota_bytes" name="quota_bytes" type="number" min="0" :value="old('quota_bytes', $b->quota_bytes ?? '')" placeholder="Unlimited" />
    </x-field>
</div>
<x-toggle name="versioning" label="Versioning" description="Keep every version of an object when it's overwritten."
    :checked="(bool) old('versioning', $b->versioning ?? false)" />
@isset($owners)
    @if ($owners->isNotEmpty())
        <x-field label="Owner" for="owner_id" hint="User who owns this bucket and its objects." :error="$errors->first('owner_id')">
            <x-select id="owner_id" name="owner_id">
                <option value="">{{ auth()->user()->name }} (me)</option>
                @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected(old('owner_id', $b->user_id ?? '') == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                @endforeach
            </x-select>
        </x-field>
        <x-field label="Also Visible To" hint="Extra users who can see this bucket. Leave empty for the owner and admins only.">
            <x-assignee-picker :users="$owners" :selected="$b?->assignees?->pluck('id')->all() ?? []" />
        </x-field>
    @endif
@endisset
