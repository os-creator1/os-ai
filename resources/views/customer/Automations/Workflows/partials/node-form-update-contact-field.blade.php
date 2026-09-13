{{--
    Automations V2 (contract §10, task requirement, V2-D) — Update contact
    field drawer. Only same-Business, canonical WRITABLE custom fields are
    ever offered: the caller (builder.blade.php's $writableFields prop) has
    already excluded any `is_phone` field, and this template renders
    nothing beyond whatever that catalog contains — phone can never appear
    here even if a future caller forgot to filter, because the option list
    is built only from this exact catalog (see workflow-builder/drawer.js).
--}}
<template id="wf-node-form-update_contact_field">
    <div class="mb-3">
        <label class="form-label">{{ __('automations.v2.update_contact_field_form.field') }}</label>
        <select class="form-select" data-field="field_id" data-role="wf-writable-field-select"></select>
    </div>
    <div class="mb-0">
        <label class="form-label">{{ __('automations.v2.update_contact_field_form.value') }}</label>
        <input type="text" class="form-control" maxlength="255" data-field="value">
    </div>
</template>
