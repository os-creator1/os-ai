{{--
    Automations V2 (contract §11) — If / Else form. Subjects are exactly
    ConditionSubjectRegistry's: V2-F's "Customer replied"
    (`contact.replied_since_enrollment`, offered now that its inbound producer
    has shipped), the contact's identity fields, subscription, group membership
    and the Business's custom fields. Nothing outside that set — no Lead,
    Booking, Form, Payment, Tag or Pipeline subject — is ever offered, because
    none is registered.

    Custom-field subjects reuse the writable-field catalog the Update contact
    field form uses (workflow-builder/drawer.js's `catalogs.writableFields`):
    it never under-offers a canonical field, and only withholds `is_phone`.
--}}
<template id="wf-node-form-if_else">
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.if_else_form.match') }}</label>
        <select class="form-select" data-field="match">
            <option value="all">{{ __('automations.v2.if_else_form.match_all') }}</option>
            <option value="any">{{ __('automations.v2.if_else_form.match_any') }}</option>
        </select>
    </div>

    <div class="wf-field">
        <p class="wf-field__label">{{ __('automations.v2.if_else_form.conditions') }}</p>
        <div class="wf-conditions" data-role="wf-conditions-list"></div>
        <button type="button" class="wf-add-condition" data-role="wf-add-condition">
            <x-ds-icon name="plus" size="16" />
            <span>{{ __('automations.v2.if_else_form.add_condition') }}</span>
        </button>
        <p class="wf-help mb-0" data-role="wf-condition-limit" hidden>{{ __('automations.v2.if_else_form.limit_reached', ['count' => \App\Library\Automation\Workflow\WorkflowLimits::MAX_CONDITIONS_PER_BRANCH]) }}</p>
    </div>

    <p class="wf-help mb-0">{{ __('automations.v2.if_else_form.paths_help') }}</p>
</template>

<template id="wf-if-else-condition-row">
    <div class="wf-condition" data-role="wf-condition-row">
        <span class="wf-condition__join" data-role="wf-condition-join" hidden></span>
        <div class="wf-condition__card">
            <div class="wf-condition__head">
                <label class="wf-field__label mb-0">{{ __('automations.v2.if_else_form.subject') }}</label>
                <button type="button" class="wf-icon-button wf-icon-button--small" data-role="wf-remove-condition" aria-label="{{ __('automations.v2.if_else_form.remove_condition') }}" title="{{ __('automations.v2.if_else_form.remove_condition') }}">
                    <x-ds-icon name="x" size="16" />
                </button>
            </div>
            <select class="form-select" data-role="wf-condition-subject">
                <optgroup label="{{ __('automations.v2.if_else_form.group_conversation') }}">
                    <option value="contact.replied_since_enrollment">{{ __('automations.v2.if_else_form.subject_replied') }}</option>
                </optgroup>
                <optgroup label="{{ __('automations.v2.if_else_form.group_contact') }}">
                    <option value="contact.first_name">{{ __('automations.v2.if_else_form.subject_first_name') }}</option>
                    <option value="contact.last_name">{{ __('automations.v2.if_else_form.subject_last_name') }}</option>
                    <option value="contact.email">{{ __('automations.v2.if_else_form.subject_email') }}</option>
                    <option value="contact.company">{{ __('automations.v2.if_else_form.subject_company') }}</option>
                    <option value="contact.subscribed">{{ __('automations.v2.if_else_form.subject_subscribed') }}</option>
                    <option value="contact.in_group">{{ __('automations.v2.if_else_form.subject_in_group') }}</option>
                </optgroup>
                <optgroup label="{{ __('automations.v2.if_else_form.group_custom_fields') }}" data-role="wf-condition-custom-field-group"></optgroup>
            </select>
            <p class="wf-help" data-role="wf-condition-help" hidden>{{ __('automations.v2.if_else_form.replied_help') }}</p>

            <select class="form-select" data-role="wf-condition-operator" aria-label="{{ __('automations.v2.if_else_form.operator') }}"></select>

            <div data-role="wf-condition-operand-wrapper">
                <input type="text" class="form-control" maxlength="255" data-role="wf-condition-operand" aria-label="{{ __('automations.v2.if_else_form.operand') }}" placeholder="{{ __('automations.v2.if_else_form.operand_placeholder') }}">
            </div>
            <div data-role="wf-condition-operand-group-wrapper" hidden>
                <select class="form-select" data-role="wf-condition-operand-group" aria-label="{{ __('automations.v2.if_else_form.operand') }}"></select>
            </div>
        </div>
    </div>
</template>
