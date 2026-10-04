{{--
    Automations — the delivery block shared by Send booking link, Send form and Send
    questionnaire: how the link is delivered, and the words around it. The link itself
    is never stored in the step — it is resolved from the resource when the step runs.
    A way of sending the account cannot use is shown disabled with its reason
    (workflow-builder/drawer-actions.js, capabilities).
--}}
<div class="wf-field">
    <p class="wf-field__label">{{ __('automations.v2.link_form.channels') }}</p>
    <label class="form-check">
        <input type="checkbox" class="form-check-input" data-role="wf-channel" value="email">
        <span class="form-check-label">{{ __('automations.v2.link_form.channel_email') }}</span>
    </label>
    <p class="wf-help" data-role="wf-channel-note-email" hidden></p>
    <label class="form-check">
        <input type="checkbox" class="form-check-input" data-role="wf-channel" value="sms">
        <span class="form-check-label">{{ __('automations.v2.link_form.channel_sms') }}</span>
    </label>
    <p class="wf-help" data-role="wf-channel-note-sms" hidden></p>
</div>
<div class="wf-field">
    <label class="wf-field__label">{{ __('automations.v2.link_form.subject') }}</label>
    <input type="text" class="form-control" maxlength="200" data-field="subject" placeholder="{{ __('automations.v2.link_form.subject_placeholder') }}">
</div>
<div class="wf-field">
    <label class="wf-field__label">{{ __('automations.v2.link_form.message') }}</label>
    <textarea class="form-control" rows="4" maxlength="1000" data-field="message" placeholder="{{ __('automations.v2.link_form.message_placeholder') }}"></textarea>
    <p class="wf-help mb-0">{{ __('automations.v2.link_form.link_note') }}</p>
</div>
