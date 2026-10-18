{{--
    Automations V2 (contract §5.2, §9, §20.1) — the Trigger form.

    Fields mirror App\Library\Automation\Workflow\NodeTypeRegistry::validateTrigger()
    exactly: trigger_type, enrollment_policy(+source), failure_policy, and the
    trigger-specific fields. Only WorkflowTriggerType cases with a real producer
    are offered — contact created, a contact date, added by hand, and (V2-F)
    "Customer sends a text" (`message_received`) — plus the domains' own after-commit
    facts: a tag added or removed, a form or questionnaire submitted, an appointment
    booked, cancelled or rescheduled, a proposal or document sent or signed, a payment
    that succeeds or fails. Each is the owning domain's durable event, never a browser
    redirect.
--}}
@php
    // Grouped the way a person thinks about them. The opportunity triggers are
    // CRM sales deals (crm_*), never the Advisor's recommendations.
    $triggerChoiceGroups = [
        'crm' => [
            ['value' => 'contact_created', 'icon' => 'user-plus'],
            ['value' => 'opportunity_created', 'icon' => 'briefcase-business'],
            ['value' => 'opportunity_stage_changed', 'icon' => 'arrow-right-left'],
            ['value' => 'opportunity_won', 'icon' => 'trophy'],
            ['value' => 'opportunity_lost', 'icon' => 'circle-x'],
            ['value' => 'contact_tag_added', 'icon' => 'tag'],
            ['value' => 'contact_tag_removed', 'icon' => 'tag'],
        ],
        'messaging' => [
            ['value' => 'message_received', 'icon' => 'message-square-reply'],
        ],
        'forms' => [
            ['value' => 'form_submitted', 'icon' => 'clipboard-list'],
            ['value' => 'questionnaire_submitted', 'icon' => 'list-checks'],
        ],
        'calendar' => [
            ['value' => 'appointment_scheduled', 'icon' => 'calendar-check'],
            ['value' => 'appointment_rescheduled', 'icon' => 'calendar-clock'],
            ['value' => 'appointment_cancelled', 'icon' => 'calendar-x'],
        ],
        'documents' => [
            ['value' => 'document_sent', 'icon' => 'file-text'],
            ['value' => 'document_signed', 'icon' => 'file-signature'],
        ],
        'payments' => [
            ['value' => 'payment_succeeded', 'icon' => 'circle-check'],
            ['value' => 'payment_failed', 'icon' => 'circle-alert'],
        ],
        'manual_time' => [
            ['value' => 'manual_enrollment', 'icon' => 'hand'],
            ['value' => 'contact_date_reached', 'icon' => 'calendar-clock'],
        ],
    ];
@endphp
<template id="wf-node-form-trigger">
    <fieldset class="wf-field">
        <legend class="wf-field__label">{{ __('automations.v2.trigger_form.trigger_type') }}</legend>
        @foreach ($triggerChoiceGroups as $groupKey => $triggerChoices)
            <p class="wf-choices__group">{{ __('automations.v2.trigger_form.group_' . $groupKey) }}</p>
            <div class="wf-choices" data-role="wf-trigger-type">
                @foreach ($triggerChoices as $choice)
                    <label class="wf-choice">
                        <input type="radio" class="wf-choice__input" name="wf-trigger-type" value="{{ $choice['value'] }}">
                        <span class="wf-icon wf-choice__icon"><x-ds-icon :name="$choice['icon']" size="18" /></span>
                        <span class="wf-choice__text">
                            <span class="wf-choice__title">{{ __('automations.v2.triggers.' . $choice['value'] . '.title') }}</span>
                            <span class="wf-choice__description">{{ __('automations.v2.triggers.' . $choice['value'] . '.description') }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        @endforeach
    </fieldset>

    <div class="wf-field-group" data-trigger-section="contact_created">
        <div class="wf-field">
            <label class="wf-field__label">{{ __('automations.v2.trigger_form.contact_group') }}</label>
            <select class="form-select" data-field="contact_group_id" data-role="wf-contact-group-select">
                <option value="">{{ __('automations.v2.trigger_form.any_group') }}</option>
            </select>
        </div>
        <div class="wf-field">
            <label class="wf-field__label">{{ __('automations.v2.trigger_form.contact_source') }}</label>
            <select class="form-select" data-field="source">
                <option value="any">{{ __('automations.v2.trigger_form.source_any') }}</option>
                <option value="opt_in_form">{{ __('automations.v2.trigger_form.source_opt_in_form') }}</option>
                <option value="in_app">{{ __('automations.v2.trigger_form.source_in_app') }}</option>
            </select>
        </div>
    </div>

    <div class="wf-field-group" data-trigger-section="message_received" hidden>
        <p class="wf-help">
            <x-ds-icon name="info" size="16" />
            <span>{{ __('automations.v2.trigger_form.message_received_note', ['hours' => \App\Library\Automation\Workflow\WorkflowLimits::MESSAGE_RECEIVED_COOLDOWN_HOURS]) }}</span>
        </p>
    </div>

    <div class="wf-field-group" data-trigger-section="contact_date_reached" hidden>
        <div class="wf-field">
            <label class="wf-field__label">{{ __('automations.v2.trigger_form.contact_group') }}</label>
            <select class="form-select" data-field="date_contact_group_id" data-role="wf-date-contact-group-select"></select>
        </div>
        <div class="wf-field">
            <label class="wf-field__label">{{ __('automations.v2.trigger_form.date_field') }}</label>
            <select class="form-select" data-field="date_field_id" data-role="wf-date-field-select"></select>
        </div>
        <div class="row g-2">
            <div class="col-7 wf-field">
                <label class="wf-field__label">{{ __('automations.v2.trigger_form.offset') }}</label>
                <select class="form-select" data-field="offset" data-role="wf-offset-select"></select>
            </div>
            <div class="col-5 wf-field">
                <label class="wf-field__label">{{ __('automations.v2.trigger_form.send_at') }}</label>
                <input type="time" class="form-control" data-field="send_at" value="09:00">
            </div>
        </div>
    </div>

    {{-- "Opportunity moves stage" — every filter optional; options come only
         from this Business's CRM catalog (drawer.js, catalogs.crmPipelines /
         catalogs.crmStages). --}}
    <div class="wf-field-group" data-trigger-section="opportunity_stage_changed" hidden>
        <div class="wf-field">
            <label class="wf-field__label">{{ __('automations.v2.trigger_form.pipeline') }}</label>
            <select class="form-select" data-field="pipeline_id" data-role="wf-crm-pipeline-select"></select>
        </div>
        <div class="wf-field">
            <label class="wf-field__label">{{ __('automations.v2.trigger_form.from_stage') }}</label>
            <select class="form-select" data-field="from_stage_id" data-role="wf-crm-from-stage-select"></select>
        </div>
        <div class="wf-field">
            <label class="wf-field__label">{{ __('automations.v2.trigger_form.to_stage') }}</label>
            <select class="form-select" data-field="to_stage_id" data-role="wf-crm-to-stage-select"></select>
        </div>
        <p class="wf-help" data-role="wf-crm-no-pipelines" hidden>{{ __('automations.v2.trigger_form.no_pipelines') }}</p>
    </div>

    @foreach (['opportunity_created', 'opportunity_stage_changed', 'opportunity_won', 'opportunity_lost'] as $opportunityTrigger)
        <div class="wf-field-group" data-trigger-note="{{ $opportunityTrigger }}" hidden>
            <p class="wf-help">
                <x-ds-icon name="info" size="16" />
                <span>{{ __('automations.v2.trigger_form.opportunity_contact_note') }}</span>
            </p>
        </div>
    @endforeach

    {{-- Contact tag triggers — one optional filter, this Business's tags only
         (drawer.js, catalogs.tags). A section lists every trigger it serves. --}}
    <div class="wf-field-group" data-trigger-section="contact_tag_added contact_tag_removed" hidden>
        <div class="wf-field">
            <label class="wf-field__label">{{ __('automations.v2.trigger_form.tag_filter') }}</label>
            <select class="form-select" data-field="tag_id" data-role="wf-tag-filter-select"></select>
        </div>
        <p class="wf-help" data-role="wf-no-tags" hidden>{{ __('automations.v2.trigger_form.no_tags') }}</p>
        <p class="wf-help">
            <x-ds-icon name="info" size="16" />
            <span>{{ __('automations.v2.trigger_form.tag_loop_note') }}</span>
        </p>
    </div>

    {{-- "Form submitted" and "Questionnaire submitted" — one optional filter, this
         Business's forms (or, for a questionnaire, only those of two or more pages)
         (drawer.js, catalogs.forms). --}}
    <div class="wf-field-group" data-trigger-section="form_submitted questionnaire_submitted" hidden>
        <div class="wf-field">
            <label class="wf-field__label">{{ __('automations.v2.trigger_form.form_filter') }}</label>
            <select class="form-select" data-field="form_id" data-role="wf-form-filter-select"></select>
        </div>
        <p class="wf-help" data-role="wf-no-forms" hidden>{{ __('automations.v2.trigger_form.no_forms') }}</p>
    </div>
    <div class="wf-field-group" data-trigger-note="form_submitted" hidden>
        <p class="wf-help">
            <x-ds-icon name="info" size="16" />
            <span>{{ __('automations.v2.trigger_form.form_note') }}</span>
        </p>
    </div>
    <div class="wf-field-group" data-trigger-note="questionnaire_submitted" hidden>
        <p class="wf-help">
            <x-ds-icon name="info" size="16" />
            <span>{{ __('automations.v2.trigger_form.questionnaire_note') }}</span>
        </p>
    </div>

    {{-- Document and payment triggers — one optional filter: the kind of document
         (a proposal or contract, or an invoice). --}}
    <div class="wf-field-group" data-trigger-section="document_sent document_signed payment_succeeded payment_failed" hidden>
        <div class="wf-field">
            <label class="wf-field__label">{{ __('automations.v2.trigger_form.document_kind') }}</label>
            <select class="form-select" data-field="document_kind" data-role="wf-document-kind-select">
                <option value="">{{ __('automations.v2.trigger_form.any_document') }}</option>
                <option value="proposal">{{ __('automations.v2.trigger_form.kind_proposal') }}</option>
                <option value="invoice">{{ __('automations.v2.trigger_form.kind_invoice') }}</option>
            </select>
        </div>
    </div>
    @foreach (['document_sent', 'document_signed'] as $documentTrigger)
        <div class="wf-field-group" data-trigger-note="{{ $documentTrigger }}" hidden>
            <p class="wf-help">
                <x-ds-icon name="info" size="16" />
                <span>{{ __('automations.v2.trigger_form.document_note') }}</span>
            </p>
        </div>
    @endforeach
    @foreach (['payment_succeeded', 'payment_failed'] as $paymentTrigger)
        <div class="wf-field-group" data-trigger-note="{{ $paymentTrigger }}" hidden>
            <p class="wf-help">
                <x-ds-icon name="info" size="16" />
                <span>{{ __('automations.v2.trigger_form.payment_note') }}</span>
            </p>
        </div>
    @endforeach

    @foreach (['appointment_scheduled', 'appointment_cancelled', 'appointment_rescheduled'] as $appointmentTrigger)
        <div class="wf-field-group" data-trigger-note="{{ $appointmentTrigger }}" hidden>
            <p class="wf-help">
                <x-ds-icon name="info" size="16" />
                <span>{{ __('automations.v2.trigger_form.appointment_note') }}</span>
            </p>
        </div>
    @endforeach

    <div class="wf-field-group" data-trigger-section="manual_enrollment" hidden>
        <p class="wf-help">
            <x-ds-icon name="info" size="16" />
            <span>{{ __('automations.v2.trigger_form.manual_enrollment_note') }}</span>
        </p>
    </div>

    {{-- WHERE IT APPLIES — the whole business, ONE Location, or a chosen list of
         them. Options are this Business's own Locations the actor reaches
         (drawer.js, catalogs.locations). A limited workflow only ever starts from
         facts of those Locations, and every run is still pinned to the ONE Location of
         the fact that started it; changing the scope means publishing again, never
         editing a live version. There is no per-step Location. --}}
    <fieldset class="wf-field" data-role="wf-scope">
        <legend class="wf-field__label">{{ __('automations.v2.trigger_form.location_scope') }}</legend>
        <div class="wf-choices" data-role="wf-scope-choices">
            @foreach (['business', 'one', 'selected'] as $scopeMode)
                <label class="wf-choice">
                    <input type="radio" class="wf-choice__input" name="wf-scope-mode" value="{{ $scopeMode }}">
                    <span class="wf-choice__text">
                        <span class="wf-choice__title">{{ __('automations.v2.trigger_form.scope_mode_' . $scopeMode) }}</span>
                        <span class="wf-choice__description">{{ __('automations.v2.trigger_form.scope_mode_' . $scopeMode . '_help') }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        <p class="wf-help" data-role="wf-scope-business-note" hidden>{{ __('automations.v2.trigger_form.scope_business_note') }}</p>
        <div class="wf-field" data-role="wf-scope-one-fields" hidden>
            <select class="form-select" data-field="business_location_id" data-role="wf-location-scope-select" aria-label="{{ __('automations.v2.trigger_form.scope_choose_location') }}"></select>
        </div>
        <div class="wf-field" data-role="wf-scope-selected-fields" hidden>
            <div data-role="wf-location-scope-list" aria-label="{{ __('automations.v2.trigger_form.scope_choose_locations') }}"></div>
        </div>
        <p class="wf-help" data-role="wf-scope-no-locations" hidden>{{ __('automations.v2.trigger_form.scope_no_locations') }}</p>
        <p class="wf-help mb-0">{{ __('automations.v2.trigger_form.location_scope_help') }}</p>
    </fieldset>
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.trigger_form.enrollment_policy') }}</label>
        <select class="form-select" data-field="enrollment_policy" data-role="wf-enrollment-policy">
            <option value="once_ever">{{ __('automations.v2.trigger_form.policy_once_ever') }}</option>
            <option value="once_per_occurrence">{{ __('automations.v2.trigger_form.policy_once_per_occurrence') }}</option>
        </select>
        <input type="hidden" data-field="enrollment_policy_source" value="default">
        <p class="wf-help wf-help--warning" data-role="wf-policy-confirm-note" hidden>
            {{ __('automations.v2.trigger_form.enrollment_policy_confirm') }}
        </p>
    </div>

    <div class="wf-field">
        <p class="wf-field__label">{{ __('automations.v2.trigger_form.failure_policy') }}</p>
        <p class="wf-help mb-0">{{ __('automations.v2.trigger_form.failure_policy_note') }}</p>
        <input type="hidden" data-field="failure_policy" value="halt">
    </div>
</template>
