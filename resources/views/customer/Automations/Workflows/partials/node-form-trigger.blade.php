{{--
    Automations V2 (contract §5.2, §9, §20.1, V2-D) — the Trigger drawer.

    Fields mirror App\Library\Automation\Workflow\NodeTypeRegistry::validateTrigger()
    exactly: trigger_type, enrollment_policy(+source), failure_policy, and the
    trigger-specific fields. Only WorkflowTriggerType cases with a real
    producer today are offered — no Forms/Calendar/Payment/Tag trigger, and
    `message_received` is withheld until V2-F ships its producer.
--}}
<template id="wf-node-form-trigger">
    <div class="mb-3">
        <label class="form-label">{{ __('automations.v2.trigger_form.trigger_type') }}</label>
        <select class="form-select" data-field="trigger_type" data-role="wf-trigger-type">
            <option value="contact_created">{{ __('A contact is created') }}</option>
            <option value="contact_date_reached">{{ __('A contact date arrives') }}</option>
            <option value="manual_enrollment">{{ __('I enroll someone by hand') }}</option>
        </select>
    </div>

    <div class="mb-3" data-role="wf-trigger-contact-created-fields">
        <label class="form-label">{{ __('automations.v2.trigger_form.contact_source') }}</label>
        <select class="form-select" data-field="source">
            <option value="any">{{ __('Any source') }}</option>
            <option value="opt_in_form">{{ __('Opt-in form') }}</option>
            <option value="in_app">{{ __('Added in app') }}</option>
        </select>

        <label class="form-label mt-2">{{ __('automations.v2.trigger_form.contact_group') }}</label>
        <select class="form-select" data-field="contact_group_id" data-role="wf-contact-group-select">
            <option value="">{{ __('automations.v2.trigger_form.any_group') }}</option>
        </select>
    </div>

    <div class="mb-3 d-none" data-role="wf-trigger-date-fields">
        <label class="form-label">{{ __('automations.v2.trigger_form.contact_group') }}</label>
        <select class="form-select" data-field="date_contact_group_id" data-role="wf-date-contact-group-select">
        </select>

        <label class="form-label mt-2">{{ __('automations.v2.trigger_form.date_field') }}</label>
        <select class="form-select" data-field="date_field_id" data-role="wf-date-field-select"></select>

        <label class="form-label mt-2">{{ __('automations.v2.trigger_form.offset') }}</label>
        <select class="form-select" data-field="offset" data-role="wf-offset-select"></select>

        <label class="form-label mt-2">{{ __('automations.v2.trigger_form.send_at') }}</label>
        <input type="time" class="form-control" data-field="send_at" value="09:00">
    </div>

    <div class="mb-3">
        <label class="form-label">{{ __('automations.v2.trigger_form.enrollment_policy') }}</label>
        <select class="form-select" data-field="enrollment_policy" data-role="wf-enrollment-policy">
            <option value="once_ever">{{ __('Once ever') }}</option>
            <option value="once_per_occurrence">{{ __('Once each time it happens') }}</option>
        </select>
        <input type="hidden" data-field="enrollment_policy_source" value="default">
        <p class="text-caption mt-1 d-none" data-role="wf-policy-confirm-note">
            {{ __('automations.v2.trigger_form.enrollment_policy_confirm') }}
        </p>
    </div>

    <div class="mb-0">
        <label class="form-label">{{ __('automations.v2.trigger_form.failure_policy') }}</label>
        <input type="text" class="form-control" value="{{ __('If a step fails, the workflow stops for that contact.') }}" disabled>
        <input type="hidden" data-field="failure_policy" value="halt">
        <p class="text-caption mt-1">{{ __('automations.v2.trigger_form.failure_policy_note') }}</p>
    </div>
</template>
