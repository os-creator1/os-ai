{{--
    Automations — Send email form. A subject and a body, nothing about who
    sends: the action reaches a mailbox only through the Business Email
    foundation's send seam, which resolves the Business's own connected sender
    and attributes the Location. There is no account, provider or address
    picker to persist here — persisting one would go stale or cross Businesses.
--}}
<template id="wf-node-form-send_email">
    <div class="wf-field">
        <label class="wf-field__label" for="wf-email-subject">{{ __('automations.v2.send_email_form.subject') }}</label>
        <input type="text" class="form-control" id="wf-email-subject" maxlength="200" data-field="subject" placeholder="{{ __('automations.v2.send_email_form.subject_placeholder') }}">
    </div>
    <div class="wf-field">
        <label class="wf-field__label" for="wf-email-body">{{ __('automations.v2.send_email_form.body') }}</label>
        <textarea class="form-control" id="wf-email-body" rows="8" maxlength="20000" data-field="body" placeholder="{{ __('automations.v2.send_email_form.placeholder') }}"></textarea>
    </div>
    <div class="wf-field">
        <p class="wf-field__label">{{ __('automations.v2.send_sms_form.personalise') }}</p>
        <div class="wf-chips">
            @foreach (['{first_name}' => 'first_name', '{last_name}' => 'last_name', '{company}' => 'company', '{business_name}' => 'business_name'] as $tag => $key)
                <button type="button" class="wf-chip" data-insert="{{ $tag }}">{{ __('automations.v2.send_sms_form.tag_' . $key) }}</button>
            @endforeach
        </div>
        <p class="wf-help mb-0">{{ __('automations.v2.send_email_form.sender_note') }}</p>
    </div>
</template>
