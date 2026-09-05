{{--
    Shared message composer: template select, AI generate button, message
    textarea + SMS counter, optional DLT template id field, optional merge
    tag select (campaign builder only).
    Params: $idSuffix, $templates, $showDlt, $showAvailableTag (bool).
--}}
@php($showAvailableTag = $showAvailableTag ?? false)
<div class="row align-items-end mb-1">
    <div class="{{ $showAvailableTag ? 'col-md-6' : 'col-md-6' }} col-12">
        <label for="sms_template-{{ $idSuffix }}" class="form-label">
            {{ __('locale.permission.sms_template') }}
            <small class="text-muted">({{ __('locale.labels.optional') }})</small>
        </label>
        <select class="form-select select2" id="sms_template-{{ $idSuffix }}" data-role="sms-template">
            <option value="">{{ __('locale.labels.select_one') }}</option>
            @foreach($templates as $template)
                <option value="{{ $template->id }}">{{ $template->name }}</option>
            @endforeach
        </select>
    </div>

    @if($showAvailableTag)
        <div class="col-md-6 col-12">
            <label class="form-label" for="available_tag-{{ $idSuffix }}">{{ __('locale.labels.available_tag') }}</label>
            <select class="form-select select2" id="available_tag-{{ $idSuffix }}" data-role="available-tag"></select>
        </div>
    @elseif(config('services.openai.active'))
        <div class="col-md-6 col-12 text-md-end mt-2 mt-md-0">
            <label class="form-label d-none d-md-block">&nbsp;</label>
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#aiMessageModal" data-role="ai-generate-trigger">
                ✨ {{ __('locale.labels.generate_with_ai') }}
            </button>
        </div>
    @endif
</div>

@if($showDlt)
    <div class="col-12">
        <div class="mb-1">
            <label for="dlt_template_id-{{ $idSuffix }}" class="form-label required">{{ __('locale.templates.dlt_template_id') }}</label>
            <input type="text" id="dlt_template_id-{{ $idSuffix }}" class="form-control @error('dlt_template_id') is-invalid @enderror" name="dlt_template_id" data-role="dlt-template-id" required>
            @error('dlt_template_id')
            <p><small class="text-danger">{{ $message }}</small></p>
            @enderror
        </div>
    </div>
@endif

<div class="col-12">
    <div class="mb-1 position-relative">
        <div class="d-flex justify-content-between align-items-center">
            <label for="message-{{ $idSuffix }}" class="form-label required fw-semibold mb-0">{{ __('locale.labels.message') }}</label>
            @if($showAvailableTag && config('services.openai.active'))
                <button type="button" class="btn btn-outline-primary mb-1 btn-sm" data-bs-toggle="modal" data-bs-target="#aiMessageModal" data-role="ai-generate-trigger">
                    ✨ {{ __('locale.labels.generate_with_ai') }}
                </button>
            @endif
        </div>

        <textarea placeholder="{{ __('locale.labels.type_message') }}" class="form-control" name="message" rows="5" id="message-{{ $idSuffix }}" data-role="message"></textarea>

        <div class="d-flex justify-content-between mt-1 small text-uppercase text-primary">
            <div>
                {{ __('locale.labels.remaining') }}:
                <span data-role="remaining" class="fw-bold">160</span>
                ( <span class="text-success" data-role="char-count">0</span> {{ __('locale.labels.characters') }} )
            </div>
            <div>
                {{ __('locale.labels.message') }}(s):
                <span data-role="messages-count" class="fw-bold">1</span>
                ({{ __('locale.labels.encoding') }}: <span class="text-success" data-role="encoding">GSM_7BIT</span>)
            </div>
        </div>

        @error('message')
        <small class="text-danger d-block mt-1">{{ $message }}</small>
        @enderror
    </div>
</div>

<div class="outreach-ai-loader position-fixed top-0 start-0 w-100 h-100 bg-white bg-opacity-75 d-none justify-content-center align-items-center" style="z-index: 1055;">
    <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
        <span class="visually-hidden">{{ __('locale.labels.loading') }} ...</span>
    </div>
</div>
