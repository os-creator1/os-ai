{{--
    Automations — Remove tag form. The same single select as Add tag; an
    archived tag can still be taken OFF a contact, so it is offered here too.
--}}
<template id="wf-node-form-remove_tag">
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.tag_action_form.tag') }}</label>
        <select class="form-select" data-field="tag_id" data-role="wf-tag-select"></select>
        <p class="wf-help" data-role="wf-no-tags" hidden>{{ __('automations.v2.tag_action_form.no_tags') }}</p>
        <p class="wf-help mb-0">{{ __('automations.v2.tag_action_form.remove_help') }}</p>
    </div>
</template>
