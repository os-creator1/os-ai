{{--
    B3 Simplified Platform Settings §8. Collapsed/default-secondary
    section: custom script (kept, admin-only, warning-framed — not
    broadened or removed), default customer permissions (kept, moved out
    of a top-level tab), read-only system diagnostics (no server scan on
    an ordinary page render), and links to the standalone destructive/CMS
    pages that stay their own screens.

    This tab is shown to anyone holding 'general settings' OR
    'authentication settings' (SettingsController::general()), but its
    two sub-forms keep their own, narrower, pre-existing abilities:
    custom script/diagnostics/links require 'general settings' (unchanged
    — same ability postGeneral()/PostGeneralRequest already require);
    default customer permissions requires 'authentication settings'
    (unchanged — DefaultCustomerPermission::authorize()'s own ability,
    which is what the pre-B3 tab shell already scoped that sub-form to).
--}}

@can('general settings')
    <h6 class="text-section-heading">Custom script</h6>
    <button class="btn btn-outline-danger btn-sm mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#platform-settings-custom-script" aria-expanded="false">
        Show custom script editor
    </button>
    <div class="collapse" id="platform-settings-custom-script">
        <x-alert variant="danger" class="mb-3">
            Advanced: custom code inserted here is executed across platform pages, including the sign-in page seen by every visitor. Only add code you fully trust.
        </x-alert>
        <form class="form form-vertical" action="{{ route('admin.settings.general') }}" method="post">
            @csrf
            <input type="hidden" name="tab" value="advanced">
            <input type="hidden" name="app_name" value="{{ config('app.name') }}">
            <input type="hidden" name="app_title" value="{{ config('app.title') }}">
            <input type="hidden" name="company_address" value="{{ \App\Helpers\Helper::app_config('company_address') }}">
            <input type="hidden" name="country" value="{{ config('app.country') }}">
            <input type="hidden" name="timezone" value="{{ config('app.timezone') }}">
            <input type="hidden" name="date_format" value="{{ config('app.date_format') }}">
            <input type="hidden" name="time_format" value="{{ config('app.time_format') }}">
            <input type="hidden" name="language" value="{{ config('app.locale') }}">

            <div class="ds-field mb-3">
                <label for="custom_script" class="form-label text-label">{{ __('locale.settings.custom_script') }}</label>
                <textarea id="custom_script" name="custom_script" rows="8" class="form-control transition-fast">{{ old('custom_script', \App\Helpers\Helper::app_config('custom_script')) }}</textarea>
                @error('custom_script')<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
            </div>

            <button type="submit" class="btn btn-outline-danger mb-3">
                <x-ds-icon name="save" size="16" /> {{ __('locale.buttons.save') }}
            </button>
        </form>
    </div>

    <hr>
@endcan

@can('authentication settings')
    <h6 class="text-section-heading">Default customer permissions</h6>
    <form class="form form-vertical" action="{{ route('admin.settings.permissions') }}" method="post">
        @csrf
        <input type="hidden" value="access_backend" name="permissions[access_backend]">

        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="platform-settings-permissions-select-all" />
            <label class="form-check-label text-uppercase" for="platform-settings-permissions-select-all">{{ __('locale.labels.select_all') }}</label>
        </div>

        @foreach ($permissionGroups as $category)
            <div class="divider divider-start divider-info mt-3">
                <div class="divider-text text-uppercase fw-bold text-primary">{{ __('locale.menu.' . $category['title']) }}</div>
            </div>
            <div class="d-flex flex-wrap gap-3 mt-1">
                @foreach ($category['permissions'] as $permission)
                    <div class="form-check">
                        <input
                            type="checkbox"
                            @if (isset($existingPermissions) && is_array($existingPermissions) && in_array($permission['name'], $existingPermissions)) checked @endif
                            @if ($permission['name'] === 'access_backend') disabled @endif
                            value="{{ $permission['name'] }}"
                            name="permissions[]"
                            class="form-check-input platform-settings-permission-checkbox"
                            id="permission_{{ $permission['name'] }}"
                        >
                        <label class="form-check-label text-uppercase" for="permission_{{ $permission['name'] }}">{{ __('locale.permission.' . $permission['display_name']) }}</label>
                    </div>
                @endforeach
            </div>
        @endforeach

        <button type="submit" class="btn btn-primary mt-3">
            <x-ds-icon name="save" size="16" /> {{ __('locale.buttons.save_changes') }}
        </button>
    </form>

    <hr>
@endcan

@can('general settings')
    <h6 class="text-section-heading">System diagnostics</h6>
    <p class="text-caption mb-1">
        Detected PHP CLI path: <code>{{ $phpBinPath ?: '(not set)' }}</code><br>
        PHP <code>exec()</code> available: {{ $execEnabled ? 'Yes' : 'No' }}
    </p>
    <p class="text-caption">
        Recommended crontab entry:
    </p>
    <pre class="p-2 bg-light rounded"><code>* * * * * {{ $phpBinPath ?: '{php-path}' }} -d register_argc_argv=On {{ base_path() }}/artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code></pre>

    <hr>

    <h6 class="text-section-heading">More settings</h6>
    <ul class="list-unstyled">
        <li><a href="{{ route('admin.settings.maintenance-mode') }}">{{ __('locale.menu.Maintenance Mode') }} &rarr;</a></li>
        <li><a href="{{ route('admin.settings.terms-of-use') }}">{{ __('locale.labels.terms_of_use') }} &rarr;</a></li>
        <li><a href="{{ route('admin.settings.privacy-policy') }}">{{ __('locale.labels.privacy_policy') }} &rarr;</a></li>
    </ul>
@endcan

<script>
    (function () {
        var selectAll = document.getElementById('platform-settings-permissions-select-all');
        var checkboxes = document.querySelectorAll('.platform-settings-permission-checkbox');

        if (!selectAll) {
            return;
        }

        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (checkbox) {
                if (!checkbox.disabled) {
                    checkbox.checked = selectAll.checked;
                }
            });
        });
    })();
</script>
