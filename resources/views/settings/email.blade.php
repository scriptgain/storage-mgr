@php
    $g = fn ($k) => $v[$k] ?? '';
@endphp
<x-layouts.app title="Email Delivery">
    <x-page-header title="Email Delivery" icon="envelope" subtitle="Choose how this install sends outgoing email."
        :back="['href' => route('settings.index'), 'label' => 'Settings']" />

    <form method="POST" action="{{ route('settings.email.update') }}" class="space-y-6"
          x-data="{ transport: '{{ $g('mail_transport') ?: 'log' }}' }">
        @csrf
        @method('PUT')

        <x-card title="Transport" subtitle="How outgoing mail leaves this install.">
            <x-field label="Transport" for="mail_transport"
                hint="SendGrid and SMTP relay externally; Sendmail uses the local mailer; Log writes to the application log without sending."
                :error="$errors->first('mail_transport')">
                <x-select id="mail_transport" name="mail_transport" x-model="transport">
                    <option value="sendgrid">SendGrid (API Key)</option>
                    <option value="smtp">SMTP Server</option>
                    <option value="mail">Sendmail (Local PHP mail())</option>
                    <option value="log">Log Only (No Delivery)</option>
                </x-select>
            </x-field>
        </x-card>

        {{-- SendGrid --}}
        <div x-show="transport === 'sendgrid'" x-cloak>
            <x-card title="SendGrid" subtitle="Relays over smtp.sendgrid.net using an API key.">
                <x-field label="API Key" for="sendgrid_api_key" hint="Leave blank to keep the stored key."
                    :error="$errors->first('sendgrid_api_key')">
                    <x-input id="sendgrid_api_key" name="sendgrid_api_key" type="password"
                        autocomplete="new-password" data-lpignore="true" placeholder="SG.xxxxxxxxxxxx" />
                </x-field>
            </x-card>
        </div>

        {{-- SMTP --}}
        <div x-show="transport === 'smtp'" x-cloak>
            <x-card title="SMTP Server" subtitle="Connect to your own outgoing mail server.">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <x-field label="Host" for="smtp_host" :error="$errors->first('smtp_host')">
                        <x-input id="smtp_host" name="smtp_host" :value="$g('smtp_host')" placeholder="smtp.example.com" />
                    </x-field>
                    <x-field label="Port" for="smtp_port" :error="$errors->first('smtp_port')">
                        <x-input id="smtp_port" name="smtp_port" type="number" :value="$g('smtp_port') ?: 587" />
                    </x-field>
                    <x-field label="Username" for="smtp_username" :error="$errors->first('smtp_username')">
                        <x-input id="smtp_username" name="smtp_username" :value="$g('smtp_username')" autocomplete="off" />
                    </x-field>
                    <x-field label="Password" for="smtp_password" hint="Leave blank to keep the stored value.">
                        <x-input id="smtp_password" name="smtp_password" type="password"
                            autocomplete="new-password" data-lpignore="true" />
                    </x-field>
                    <x-field label="Encryption" for="smtp_encryption" :error="$errors->first('smtp_encryption')">
                        <x-select id="smtp_encryption" name="smtp_encryption">
                            <option value="tls" @selected(in_array($g('smtp_encryption'), ['tls', '']))>TLS</option>
                            <option value="ssl" @selected($g('smtp_encryption') === 'ssl')>SSL</option>
                            <option value="none" @selected($g('smtp_encryption') === 'none')>None</option>
                        </x-select>
                    </x-field>
                </div>
            </x-card>
        </div>

        {{-- Sender identity (shared across transports) --}}
        <x-card title="Sender Identity" subtitle="Shown as the From on every message.">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <x-field label="From Name" for="mail_from_name" :error="$errors->first('mail_from_name')">
                    <x-input id="mail_from_name" name="mail_from_name" :value="$g('mail_from_name')"
                        placeholder="{{ config('brand.name') }}" />
                </x-field>
                <x-field label="From Address" for="mail_from" :error="$errors->first('mail_from')">
                    <x-input id="mail_from" name="mail_from" type="email" :value="$g('mail_from')"
                        placeholder="no-reply@example.com" />
                </x-field>
            </div>
        </x-card>

        <div class="flex items-center justify-end gap-2">
            <x-button variant="secondary" href="{{ route('settings.index') }}">Cancel</x-button>
            <x-button type="submit" icon="check">Save</x-button>
        </div>
    </form>

    {{-- Send a test email using the currently saved configuration --}}
    <x-card title="Send Test Email" subtitle="Uses the currently saved configuration, not unsaved changes above." class="mt-6">
        <form method="POST" action="{{ route('settings.email.test') }}" class="flex flex-col sm:flex-row sm:items-end gap-3">
            @csrf
            <div class="flex-1">
                <x-field label="Send To" for="test_to" :error="$errors->first('test_to')">
                    <x-input id="test_to" name="test_to" type="email" :value="old('test_to', $testTo)" placeholder="you@example.com" />
                </x-field>
            </div>
            <x-button type="submit" variant="secondary" icon="envelope">Send Test Email</x-button>
        </form>
    </x-card>
</x-layouts.app>
