{{--
    Automations — Move opportunity form. A pipeline and a stage of it, both from
    this Business's own CRM catalog (workflow-builder/drawer-actions.js,
    catalogs.crmPipelines / catalogs.crmStages): never another Business's row, and
    never an id shown to a person. The deal itself is NOT chosen here — it is the
    journey's own, resolved safely when the step runs (the deal the trigger names, else
    the contact's one open deal in this pipeline).
--}}
<template id="wf-node-form-move_opportunity">
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.move_form.pipeline') }}</label>
        <select class="form-select" data-field="pipeline_id" data-role="wf-move-pipeline-select"></select>
        <p class="wf-help" data-role="wf-move-no-pipelines" hidden>{{ __('automations.v2.move_form.no_pipelines') }}</p>
    </div>
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.move_form.stage') }}</label>
        <select class="form-select" data-field="stage_id" data-role="wf-move-stage-select"></select>
    </div>
    <div class="wf-field">
        <p class="wf-help mb-0">{{ __('automations.v2.move_form.help') }}</p>
    </div>
</template>
