{{--
    Automations — Add tag form. One select, filled by workflow-builder/drawer.js
    from `catalogs.tags`: only this Business's own tags are ever offered, and an
    archived tag is hidden unless the step already names it (it then stays visible
    and marked, so the validator's message makes sense).
--}}
<template id="wf-node-form-add_tag">
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.tag_action_form.tag') }}</label>
        <select class="form-select" data-field="tag_id" data-role="wf-tag-select"></select>
        <p class="wf-help" data-role="wf-no-tags" hidden>{{ __('automations.v2.tag_action_form.no_tags') }}</p>
        <p class="wf-help mb-0">{{ __('automations.v2.tag_action_form.add_help') }}</p>
    </div>
</template>
