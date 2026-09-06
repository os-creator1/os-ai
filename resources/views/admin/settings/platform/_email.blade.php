{{--
    B3 Simplified Platform Settings §5. Reads exclusively from config()
    (never env() directly in a Blade — env() returns null once
    config:cache has run) so the redisplayed values survive a cached
    config deployment. The SMTP password is never rendered back into the
    form: type=password, always blank: a blank submission preserves the
    existing password (EloquentSettingsRepository::systemEmail()).
--}}

<form class="form form-vertical" action="{{ route('admin.settings.email') }}" method="post">
    @csrf
    <input type="hidden" name="tab" value="email">

    <div class="row">
        <div class="col-md-6">
            <x-select name="driver" label="{{ __('locale.settings.method_for_sending') }}" :options="['sendmail' => 'Sendmail', 'smtp' => 'SMTP']" :selected="old('driver', config('mail.default'))" data-reveals="#platform-settings-smtp-fields" data-reveal-value="smtp" />

            <div id="platform-settings-smtp-fields" @if (old('driver', config('mail.default')) !== 'smtp') class="d-none" @endif>
                <x-input name="host" label="Host" value="{{ old('host', config('mail.mailers.smtp.host')) }}" />
                <x-input name="port" label="Port" value="{{ old('port', config('mail.mailers.smtp.port')) }}" />
                <x-select name="encryption" label="Encryption" :options="['tls' => 'TLS', 'ssl' => 'SSL']" :selected="old('encryption', config('mail.mailers.smtp.encryption'))" />
                <x-input name="username" label="Username" value="{{ old('username', config('mail.mailers.smtp.username')) }}" />
                <x-input type="password" name="password" label="Password" value="" help="Leave blank to keep the current password." />
            </div>
        </div>

        <div class="col-md-6">
            <x-input type="email" name="from_email" label="{{ __('locale.settings.from_email') }}" value="{{ old('from_email', config('mail.from.address')) }}" required />
            <x-input name="from_name" label="{{ __('locale.settings.from_name') }}" value="{{ old('from_name', config('mail.from.name')) }}" required />
        </div>
    </div>

    <button type="submit" class="btn btn-primary mb-1">
        <x-ds-icon name="save" size="16" /> {{ __('locale.buttons.save') }}
    </button>
</form>

<hr>

<h6 class="text-section-heading">Send test email</h6>
<p class="text-caption">Sends a short message using the mail configuration currently saved above.</p>
<form class="form form-inline d-flex gap-2 align-items-start" action="{{ route('admin.settings.email.test') }}" method="post">
    @csrf
    <div class="flex-grow-1" style="max-width: 320px;">
        <x-input type="email" name="email" placeholder="you@example.com" />
    </div>
    <x-button type="submit" variant="outline">Send test email</x-button>
</form>

<p class="text-caption mt-3">
    <a href="{{ route('admin.email-templates.index') }}">{{ __('locale.menu.Email Templates') }} &rarr;</a>
</p>
