<x-layouts.app title="New Access Key">
    <x-page-header title="New Access Key" icon="key" subtitle="Generates an access key ID and secret key pair."
        :back="['href' => route('access-keys.index'), 'label' => 'Access Keys']" />

    <x-card>
        <form method="POST" action="{{ route('access-keys.store') }}" class="space-y-5">
            @csrf
            <x-field label="Name" for="name" required hint="A label to identify this key, e.g. 'Backup Script'." :error="$errors->first('name')">
                <x-input id="name" name="name" required :value="old('name')" placeholder="CI Deploy Key" />
            </x-field>
            <x-field label="Policy" for="policy_id" hint="Optional. Attach a policy to scope what this key can do." :error="$errors->first('policy_id')">
                <x-select id="policy_id" name="policy_id">
                    <option value="">No policy</option>
                    @foreach ($policies as $p)
                        <option value="{{ $p->id }}" @selected(old('policy_id') == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </x-select>
            </x-field>
            @if ($owners->isNotEmpty())
                <x-field label="Owner" for="owner_id" hint="User who owns this access key." :error="$errors->first('owner_id')">
                    <x-select id="owner_id" name="owner_id">
                        <option value="">{{ auth()->user()->name }} (me)</option>
                        @foreach ($owners as $owner)
                            <option value="{{ $owner->id }}" @selected(old('owner_id') == $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                        @endforeach
                    </x-select>
                </x-field>
                <x-field label="Also Visible To" hint="Extra users who can see this access key. Leave empty for the owner and admins only.">
                    <x-assignee-picker :users="$owners" :selected="[]" />
                </x-field>
            @endif
            <x-alert type="info">The secret key is generated on save and shown exactly once — copy it somewhere safe immediately.</x-alert>
            <div class="flex items-center justify-end gap-2 pt-1">
                <x-button variant="secondary" href="{{ route('access-keys.index') }}">Cancel</x-button>
                <x-button type="submit" icon="plus">Create Access Key</x-button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
