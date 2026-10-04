{{--
    Automations — Create & send proposal or contract form. A title, one product or
    package from this Business's catalog (catalogs.catalogItems), a quantity and how it
    is paid (in full, or a deposit then the balance). The Documents domain creates the
    document, takes the catalog's price at that moment, and emails the secure link for
    signing. A contract is a proposal that is signed: there is one kind.
--}}
<template id="wf-node-form-create_send_proposal">
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.proposal_form.title') }}</label>
        <input type="text" class="form-control" maxlength="200" data-field="title" placeholder="{{ __('automations.v2.proposal_form.title_placeholder') }}">
    </div>
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.proposal_form.item') }}</label>
        <select class="form-select" data-field="catalog_item_id" data-role="wf-catalog-item-select"></select>
        <p class="wf-help" data-role="wf-no-catalog-items" hidden>{{ __('automations.v2.proposal_form.no_items') }}</p>
    </div>
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.proposal_form.quantity') }}</label>
        <input type="number" class="form-control" min="1" max="99" step="1" data-field="quantity" value="1">
    </div>
    <fieldset class="wf-field">
        <legend class="wf-field__label">{{ __('automations.v2.proposal_form.schedule') }}</legend>
        <label class="form-check">
            <input type="radio" class="form-check-input" name="wf-proposal-schedule" value="full">
            <span class="form-check-label">{{ __('automations.v2.proposal_form.schedule_full') }}</span>
        </label>
        <label class="form-check">
            <input type="radio" class="form-check-input" name="wf-proposal-schedule" value="deposit">
            <span class="form-check-label">{{ __('automations.v2.proposal_form.schedule_deposit') }}</span>
        </label>
        <div class="wf-field mt-1" data-role="wf-deposit-fields" hidden>
            <label class="wf-field__label">{{ __('automations.v2.proposal_form.deposit_percent') }}</label>
            <input type="number" class="form-control" min="5" max="95" step="1" data-field="deposit_percent" value="30">
        </div>
    </fieldset>
    <div class="wf-field">
        <p class="wf-help mb-0">{{ __('automations.v2.proposal_form.email_note') }}</p>
    </div>
</template>
