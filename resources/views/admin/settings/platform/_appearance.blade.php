{{--
    B3 Simplified Platform Settings §4. The six M2-managed branding
    assets, reusing exactly BrandingUploadService (via
    SettingsController::postGeneral()) / ValidBrandingImageRule / the
    existing x-branding-* presentation — never AppConfig::uploadFile().
    Each removable asset gets a "Remove" action wired to the
    already-implemented BrandingUploadService::delete() through
    SettingsController::removeBrandingAsset(), allowlisted to exactly
    these six logical keys by RemoveBrandingAssetRequest. Colors, fonts,
    and preset activation stay exclusively on the M2 Theme Presets page
    linked below — nothing here duplicates a theme token control.
--}}

<div class="mb-3">
    <x-alert variant="accent">
        Looking for colors, fonts, or theme presets? Manage those on the
        <a href="{{ route('admin.theme-presets.index') }}">Theme Presets</a> page.
    </x-alert>
</div>

<form class="form form-vertical" action="{{ route('admin.settings.general') }}" method="post" enctype="multipart/form-data">
    @csrf
    <input type="hidden" name="tab" value="appearance">

    {{-- Hidden platform fields this same endpoint also validates, carried
         through unchanged so switching straight to this tab and saving
         only branding never blanks the Platform section's own values. --}}
    <input type="hidden" name="app_name" value="{{ config('app.name') }}">
    <input type="hidden" name="app_title" value="{{ config('app.title') }}">
    <input type="hidden" name="company_address" value="{{ \App\Helpers\Helper::app_config('company_address') }}">
    <input type="hidden" name="country" value="{{ config('app.country') }}">
    <input type="hidden" name="timezone" value="{{ config('app.timezone') }}">
    <input type="hidden" name="date_format" value="{{ config('app.date_format') }}">
    <input type="hidden" name="time_format" value="{{ config('app.time_format') }}">
    <input type="hidden" name="language" value="{{ config('app.locale') }}">

    <div class="row">
        <div class="col-md-6">
            <div class="mb-4">
                <label class="form-label text-label">{{ __('locale.settings.logo') }}</label>
                <div class="mb-2"><x-branding-logo variant="full" background="light" /></div>
                <input type="file" name="app_logo" class="form-control" accept="image/png,image/jpeg,image/webp" />
                <p class="form-text text-caption">{{ __('locale.settings.logo_size') }}</p>
                @error('app_logo')<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
                @if (config('app.logo') !== 'images/branding/default-logo.svg')
                    @include('admin.settings.platform._branding-remove', ['asset' => 'app_logo', 'label' => 'logo'])
                @endif
            </div>

            <div class="mb-4">
                <label class="form-label text-label">Compact / sidebar logo</label>
                <div class="mb-2"><x-branding-logo variant="compact" background="light" /></div>
                <input type="file" name="logo_compact" class="form-control" accept="image/png,image/jpeg,image/webp" />
                <p class="form-text text-caption">Optional. Used for the collapsed sidebar and mobile header. Falls back to the full logo when not set.</p>
                @error('logo_compact')<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
                @if (config('app.logo_compact'))
                    @include('admin.settings.platform._branding-remove', ['asset' => 'logo_compact', 'label' => 'compact logo'])
                @endif
            </div>

            <div class="mb-4">
                <label class="form-label text-label">Dark-background logo</label>
                <div class="mb-2"><x-branding-logo variant="full" background="dark" /></div>
                <input type="file" name="logo_dark" class="form-control" accept="image/png,image/jpeg,image/webp" />
                <p class="form-text text-caption">Optional. Used wherever the logo is placed on a dark background. Falls back to the full, light-background logo when not set.</p>
                @error('logo_dark')<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
                @if (config('app.logo_dark'))
                    @include('admin.settings.platform._branding-remove', ['asset' => 'logo_dark', 'label' => 'dark logo'])
                @endif
            </div>
        </div>

        <div class="col-md-6">
            <div class="mb-4">
                <label class="form-label text-label">{{ __('locale.settings.favicon') }}</label>
                <div class="mb-2">
                    <img src="{{ asset(app(\App\Library\Branding\BrandingPresenter::class)->favicon()) }}" alt="Favicon" width="32" height="32" />
                </div>
                <input type="file" name="app_favicon" class="form-control" accept="image/png,image/jpeg,image/webp,image/x-icon" />
                <p class="form-text text-caption">{{ __('locale.settings.favicon_size') }}</p>
                @error('app_favicon')<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
                @if (config('app.favicon') !== 'images/branding/default-favicon.svg')
                    @include('admin.settings.platform._branding-remove', ['asset' => 'app_favicon', 'label' => 'favicon'])
                @endif
            </div>

            <div class="mb-4">
                <label class="form-label text-label">Authentication-page illustration</label>
                <input type="file" name="auth_illustration" class="form-control" accept="image/png,image/jpeg,image/webp" />
                <p class="form-text text-caption">Optional. Falls back to the bundled illustration when not set.</p>
                @error('auth_illustration')<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
                @if (config('app.auth_illustration'))
                    @include('admin.settings.platform._branding-remove', ['asset' => 'auth_illustration', 'label' => 'authentication-page illustration'])
                @endif
            </div>

            <div class="mb-4">
                <label class="form-label text-label">Installer illustration</label>
                <div class="mb-2"><x-branding-illustration surface="installer" /></div>
                <input type="file" name="installer_illustration" class="form-control" accept="image/png,image/jpeg,image/webp" />
                <p class="form-text text-caption">Optional. Falls back to the bundled illustration when not set.</p>
                @error('installer_illustration')<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
                @if (config('app.installer_illustration'))
                    @include('admin.settings.platform._branding-remove', ['asset' => 'installer_illustration', 'label' => 'installer illustration'])
                @endif
            </div>
        </div>
    </div>

    <hr>

    <div class="row">
        <div class="col-md-6">
            <x-input name="footer_company_name" label="Footer company name" value="{{ old('footer_company_name', config('app.footer_company_name')) }}" help="Optional. Falls back to the application name when not set." />
        </div>
        <div class="col-md-6">
            <x-input name="footer_copyright_text" label="Footer copyright wording" value="{{ old('footer_copyright_text', config('app.footer_copyright_text')) }}" help="Optional short suffix shown after the copyright line, e.g. &quot;All rights reserved&quot;." />
        </div>
    </div>
    <p class="text-caption mb-3">Preview: <x-branding-footer /></p>

    <button type="submit" class="btn btn-primary mb-1">
        <x-ds-icon name="save" size="16" /> {{ __('locale.buttons.save') }}
    </button>
</form>
