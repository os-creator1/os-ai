{{--
    Automations — Send form form. One of this Business's one-page forms
    (catalogs.forms), then how to deliver its public link. The link is the Forms
    domain's own deployment link at the journey's Location.
--}}
<template id="wf-node-form-send_form">
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.send_form_form.form') }}</label>
        <select class="form-select" data-field="form_id" data-role="wf-form-select"></select>
        <p class="wf-help" data-role="wf-no-forms" hidden>{{ __('automations.v2.send_form_form.no_forms') }}</p>
    </div>
    @include('customer.Automations.Workflows.partials._link-delivery')
</template>
