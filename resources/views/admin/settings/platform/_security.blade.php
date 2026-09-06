{{--
    B3 Simplified Platform Settings §6. client_can_create_subaccount/
    client_can_delete_subaccount are no longer first-class controls here
    (the legacy Sub-Accounts surface they gate is a documented
    delete-later candidate) but the write path still reads and re-writes
    their env keys on every save, so they are always submitted as hidden
    fields carrying the current value -- existing behavior is unchanged
    until that retirement lands. Social sign-in is one collapsed
    subsection, not four first-class cards. Every secret field
    (captcha_secret_key, each provider's client_secret) is type=password,
    always rendered blank: a blank submission preserves the existing
    secret (EloquentSettingsRepository::authentication()).
--}}

@php
    $yesNo = ['1' => __('locale.labels.yes'), '0' => __('locale.labels.no')];
    $boolToOption = fn ($value) => $value ? '1' : '0';
@endphp

<form class="form form-vertical" action="{{ route('admin.settings.authentication') }}" method="post">
    @csrf
    <input type="hidden" name="tab" value="security">
    <input type="hidden" name="client_can_create_subaccount" value="{{ $boolToOption(config('account.create_subaccount')) }}">
    <input type="hidden" name="client_can_delete_subaccount" value="{{ $boolToOption(config('account.delete_subaccount')) }}">
    <input type="hidden" name="two_factor_send_by" value="email">

    <div class="row">
        <div class="col-md-6">
            <x-select name="client_registration" label="{{ __('locale.settings.client_registration') }}" :options="$yesNo" :selected="old('client_registration', $boolToOption(config('account.can_register')))" />
            <x-select name="registration_verification" label="{{ __('locale.settings.registration_verification') }}" :options="$yesNo" :selected="old('registration_verification', $boolToOption(config('account.verify_account')))" />
            <x-select name="client_can_delete_account" label="{{ __('locale.settings.client_can_delete_account') }}" :options="$yesNo" :selected="old('client_can_delete_account', $boolToOption(config('account.can_delete')))" />
        </div>
        <div class="col-md-6">
            <x-select name="two_factor" label="{{ __('locale.settings.two_factor_authentication') }}" :options="$yesNo" :selected="old('two_factor', $boolToOption(config('app.two_factor')))" />
            <x-select name="captcha_in_login" label="reCAPTCHA on login" :options="$yesNo" :selected="old('captcha_in_login', $boolToOption(config('no-captcha.login')))" data-reveals="#platform-settings-captcha-fields" />
            <x-select name="captcha_in_client_registration" label="reCAPTCHA on registration" :options="$yesNo" :selected="old('captcha_in_client_registration', $boolToOption(config('no-captcha.registration')))" data-reveals="#platform-settings-captcha-fields" />
        </div>
    </div>

    <div id="platform-settings-captcha-fields" @if (! config('no-captcha.login') && ! config('no-captcha.registration')) class="d-none" @endif>
        <div class="row">
            <div class="col-md-6">
                <x-input name="captcha_site_key" label="reCAPTCHA site key" value="{{ old('captcha_site_key', config('no-captcha.sitekey')) }}" />
            </div>
            <div class="col-md-6">
                <x-input type="password" name="captcha_secret_key" label="reCAPTCHA secret key" value="" help="Leave blank to keep the current secret." />
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary mb-1">
        <x-ds-icon name="save" size="16" /> {{ __('locale.buttons.save') }}
    </button>

    <hr>

    <button class="btn btn-outline-secondary btn-sm mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#platform-settings-social" aria-expanded="false">
        Social sign-in
    </button>
    <div class="collapse" id="platform-settings-social">
        <x-alert variant="accent" class="mb-3">
            Backend-only infrastructure, retained for compatibility. Most installs never need this.
        </x-alert>

        @foreach ([
            'facebook' => 'Facebook',
            'twitter' => 'Twitter',
            'google' => 'Google',
            'github' => 'GitHub',
        ] as $provider => $providerLabel)
            <div class="mb-4">
                <h6 class="text-section-heading">{{ $providerLabel }}</h6>
                <x-select
                    name="login_with_{{ $provider }}"
                    label="Enabled"
                    :options="$yesNo"
                    :selected="old('login_with_' . $provider, $boolToOption(config('services.' . $provider . '.active') === 'true' || config('services.' . $provider . '.active') === true))"
                    data-reveals="#platform-settings-{{ $provider }}-fields"
                />
                <div id="platform-settings-{{ $provider }}-fields" @if (! (config('services.' . $provider . '.active') === 'true' || config('services.' . $provider . '.active') === true)) class="d-none" @endif>
                    <x-input name="{{ $provider }}_client_id" label="Client ID" value="{{ old($provider . '_client_id', config('services.' . $provider . '.client_id')) }}" />
                    <x-input type="password" name="{{ $provider }}_client_secret" label="Client secret" value="" help="Leave blank to keep the current secret." />
                </div>
            </div>
        @endforeach
    </div>
</form>
