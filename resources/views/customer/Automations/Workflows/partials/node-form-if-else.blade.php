{{--
    Automations V2 (contract §11, V2-D) — If/Else drawer. Subjects are
    exactly the contract §11 launch set — `contact.replied_since_enrollment`
    is withheld until V2-F ships its producer, and nothing outside this list
    (no Lead/Booking/Form/Payment/Tag subject) is ever offered, because none
    of those domains is registered in ConditionSubjectRegistry.

    Custom-field subjects reuse the same writable-field catalog the Update
    Contact Field drawer uses (workflow-builder/index.js's `catalogs.writableFields`)
    — a conservative choice in the absence of a separate "readable fields"
    catalog in the endpoint contract; it never under-offers a canonical
    field, only (harmlessly) withholds `is_phone` ones from conditions too.
--}}
<template id="wf-node-form-if_else">
    <div class="mb-3">
        <label class="form-label">{{ __('automations.v2.if_else_form.match') }}</label>
        <select class="form-select" data-field="match">
            <option value="all">{{ __('automations.v2.if_else_form.match_all') }}</option>
            <option value="any">{{ __('automations.v2.if_else_form.match_any') }}</option>
        </select>
    </div>

    <label class="form-label">{{ __('automations.v2.if_else_form.conditions') }}</label>
    <div data-role="wf-conditions-list"></div>
    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" data-role="wf-add-condition">
        {{ __('automations.v2.if_else_form.add_condition') }}
    </button>
</template>

<template id="wf-if-else-condition-row">
    <div class="border rounded p-2 mb-2" data-role="wf-condition-row">
        <div class="d-flex justify-content-end">
            <button type="button" class="btn btn-flat-secondary btn-sm" data-role="wf-remove-condition">
                {{ __('automations.v2.if_else_form.remove_condition') }}
            </button>
        </div>
        <label class="form-label">{{ __('automations.v2.if_else_form.subject') }}</label>
        <select class="form-select mb-2" data-role="wf-condition-subject">
            <optgroup label="{{ __('Contact') }}">
                <option value="contact.first_name">{{ __('First name') }}</option>
                <option value="contact.last_name">{{ __('Last name') }}</option>
                <option value="contact.email">{{ __('Email') }}</option>
                <option value="contact.company">{{ __('Company') }}</option>
                <option value="contact.subscribed">{{ __('Subscribed') }}</option>
                <option value="contact.in_group">{{ __('Contact group') }}</option>
            </optgroup>
            <optgroup label="{{ __('Custom fields') }}" data-role="wf-condition-custom-field-group"></optgroup>
        </select>

        <label class="form-label">{{ __('automations.v2.if_else_form.operator') }}</label>
        <select class="form-select mb-2" data-role="wf-condition-operator"></select>

        <div data-role="wf-condition-operand-wrapper">
            <label class="form-label">{{ __('automations.v2.if_else_form.operand') }}</label>
            <input type="text" class="form-control" maxlength="255" data-role="wf-condition-operand">
        </div>
        <div data-role="wf-condition-operand-group-wrapper" class="d-none">
            <label class="form-label">{{ __('automations.v2.if_else_form.operand') }}</label>
            <select class="form-select" data-role="wf-condition-operand-group"></select>
        </div>
    </div>
</template>
