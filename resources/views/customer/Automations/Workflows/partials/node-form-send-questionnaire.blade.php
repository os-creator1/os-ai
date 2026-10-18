{{--
    Automations — Send questionnaire form. One of this Business's multi-step
    questionnaires (a form of two or more pages), then how to deliver its public link.
--}}
<template id="wf-node-form-send_questionnaire">
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.send_form_form.questionnaire') }}</label>
        <select class="form-select" data-field="form_id" data-role="wf-form-select"></select>
        <p class="wf-help" data-role="wf-no-forms" hidden>{{ __('automations.v2.send_form_form.no_questionnaires') }}</p>
    </div>
    @include('customer.Automations.Workflows.partials._link-delivery')
</template>
