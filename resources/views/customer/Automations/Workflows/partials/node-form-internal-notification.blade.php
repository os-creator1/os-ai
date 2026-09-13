{{-- Automations V2 (contract §10, V2-D) — Internal notification form. Recipients are
     the Business owner and active members with access, never a chosen field. --}}
<template id="wf-node-form-internal_notification">
    <div class="wf-field">
        <div class="d-flex justify-content-between align-items-baseline">
            <label class="wf-field__label" for="wf-notify-message">{{ __('automations.v2.internal_notification_form.message') }}</label>
            <span class="wf-counter" data-role="wf-message-counter"></span>
        </div>
        <textarea class="form-control" id="wf-notify-message" rows="4" maxlength="255" data-field="message" placeholder="{{ __('automations.v2.internal_notification_form.placeholder') }}"></textarea>
        <p class="wf-help mb-0">{{ __('automations.v2.internal_notification_form.recipients') }}</p>
    </div>
</template>
