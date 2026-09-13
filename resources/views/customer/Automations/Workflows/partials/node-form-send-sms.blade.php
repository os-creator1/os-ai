{{--
    Automations V2 (contract §10.1, V2-D) — Send SMS form. Body only: v2
    resolves the sending path at execution time (managed identity, else the
    active BYO channel), so there is no sender/channel picker to persist
    here — persisting one would go stale or cross Businesses (§10.1).
--}}
<template id="wf-node-form-send_sms">
    <div class="wf-field">
        <div class="d-flex justify-content-between align-items-baseline">
            <label class="wf-field__label" for="wf-sms-body">{{ __('automations.v2.send_sms_form.body') }}</label>
            <span class="wf-counter" data-role="wf-body-counter"></span>
        </div>
        <textarea class="form-control" id="wf-sms-body" rows="6" maxlength="1600" data-field="body" placeholder="{{ __('automations.v2.send_sms_form.placeholder') }}"></textarea>
    </div>
    <div class="wf-field">
        <p class="wf-field__label">{{ __('automations.v2.send_sms_form.personalise') }}</p>
        <div class="wf-chips">
            @foreach (['{first_name}' => 'first_name', '{last_name}' => 'last_name', '{company}' => 'company', '{business_name}' => 'business_name'] as $tag => $key)
                <button type="button" class="wf-chip" data-insert="{{ $tag }}">{{ __('automations.v2.send_sms_form.tag_' . $key) }}</button>
            @endforeach
        </div>
        <p class="wf-help mb-0">{{ __('automations.v2.send_sms_form.testing_note') }}</p>
    </div>
</template>
