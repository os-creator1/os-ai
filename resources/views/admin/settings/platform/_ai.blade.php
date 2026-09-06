{{--
    B3 Simplified Platform Settings §7. The one canonical platform AI
    provider configuration path: config('services.openai.*'), written
    through OPENAI_ACTIVE/API_KEY/MODEL/ORGANIZATION/PROJECT/ROLE exactly
    as before -- both the existing campaign AI feature and Lane A's
    Agency Prospecting runtime read this same seam, so neither the key
    names nor config/services.php may change. The enabled toggle and the
    provider-fields save are two independently authorized actions (both
    require 'manage ai_settings'), matching the pre-B3 UI's own
    checkbox+AJAX / form-submit split. The stored API key is never
    rendered back into the form: a blank submission preserves it.
--}}

<x-alert variant="warning" class="mb-3">
    Access to the OpenAI API requires a paid OpenAI plan (separate from a ChatGPT subscription). See OpenAI's own <a href="https://openai.com/api/pricing/" target="_blank" rel="noopener">pricing</a>.
</x-alert>

<div class="form-check form-switch form-check-primary mb-3">
    <input type="checkbox" class="form-check-input" id="platform-settings-ai-enabled"
           {{ config('services.openai.active') ? 'checked' : '' }} />
    <label class="form-check-label" for="platform-settings-ai-enabled">Enable OpenAI</label>
</div>

<div id="platform-settings-ai-fields" @if (! config('services.openai.active')) class="d-none" @endif>
    <form class="form form-vertical" action="{{ route('admin.settings.ai-settings') }}" method="post">
        @csrf
        <input type="hidden" name="tab" value="ai">

        <x-input name="api_key" type="password" label="{{ __('locale.labels.api_key') }}" value="" help="Leave blank to keep the current API key." />
        <x-input name="model" label="{{ __('locale.labels.model') }} {{ __('locale.labels.name') }}" value="{{ old('model', config('services.openai.model')) }}" required />
        <x-input name="organization" label="{{ __('locale.labels.organization') }} {{ __('locale.labels.id') }}" value="{{ old('organization', config('services.openai.organization')) }}" />
        <x-input name="project" label="{{ __('locale.labels.project') }} {{ __('locale.labels.id') }}" value="{{ old('project', config('services.openai.project')) }}" />
        <x-input name="role" label="{{ __('locale.labels.role') }}" value="{{ old('role', config('services.openai.role')) }}" />

        <button type="submit" class="btn btn-primary mb-1">
            <x-ds-icon name="save" size="16" /> {{ __('locale.buttons.save') }}
        </button>
    </form>
</div>

<script>
    (function () {
        var checkbox = document.getElementById('platform-settings-ai-enabled');
        var fields = document.getElementById('platform-settings-ai-fields');

        if (!checkbox || !fields) {
            return;
        }

        checkbox.addEventListener('change', function () {
            fields.hidden = !checkbox.checked;

            fetch(@json(route('admin.settings.ai-settings.toggle')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({
                    _token: @json(csrf_token()),
                    openai_enabled: checkbox.checked ? 'true' : 'false',
                }),
            });
        });
    })();
</script>
