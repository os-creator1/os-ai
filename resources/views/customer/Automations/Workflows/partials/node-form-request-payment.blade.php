{{--
    Automations — Request payment form. NOT charging a card: it emails a secure
    payment link. Either for the document this workflow is about (offered only when the
    workflow starts from a proposal, a document or a payment), or for a new invoice made
    here from a product or a fixed amount. Delivery is the Documents domain's own email.
--}}
<template id="wf-node-form-request_payment">
    <fieldset class="wf-field">
        <legend class="wf-field__label">{{ __('automations.v2.payment_form.source') }}</legend>
        <label class="form-check">
            <input type="radio" class="form-check-input" name="wf-payment-source" value="document">
            <span class="form-check-label">{{ __('automations.v2.payment_form.source_document') }}</span>
        </label>
        <p class="wf-help" data-role="wf-payment-document-note" hidden>{{ __('automations.v2.payment_form.source_document_unavailable') }}</p>
        <label class="form-check">
            <input type="radio" class="form-check-input" name="wf-payment-source" value="invoice">
            <span class="form-check-label">{{ __('automations.v2.payment_form.source_invoice') }}</span>
        </label>
    </fieldset>

    <div class="wf-field-group" data-role="wf-payment-document-fields" hidden>
        <p class="wf-help">{{ __('automations.v2.payment_form.source_document_help') }}</p>
    </div>

    <div class="wf-field-group" data-role="wf-payment-invoice-fields">
        <div class="wf-field">
            <label class="wf-field__label">{{ __('automations.v2.payment_form.title') }}</label>
            <input type="text" class="form-control" maxlength="200" data-field="title" placeholder="{{ __('automations.v2.payment_form.title_placeholder') }}">
        </div>
        <fieldset class="wf-field">
            <legend class="wf-field__label">{{ __('automations.v2.payment_form.basis') }}</legend>
            <label class="form-check">
                <input type="radio" class="form-check-input" name="wf-payment-basis" value="item">
                <span class="form-check-label">{{ __('automations.v2.payment_form.basis_item') }}</span>
            </label>
            <label class="form-check">
                <input type="radio" class="form-check-input" name="wf-payment-basis" value="amount">
                <span class="form-check-label">{{ __('automations.v2.payment_form.basis_amount') }}</span>
            </label>
        </fieldset>
        <div class="wf-field-group" data-role="wf-payment-item-fields">
            <div class="wf-field">
                <label class="wf-field__label">{{ __('automations.v2.proposal_form.item') }}</label>
                <select class="form-select" data-field="catalog_item_id" data-role="wf-catalog-item-select"></select>
                <p class="wf-help" data-role="wf-no-catalog-items" hidden>{{ __('automations.v2.proposal_form.no_items') }}</p>
            </div>
            <div class="wf-field">
                <label class="wf-field__label">{{ __('automations.v2.proposal_form.quantity') }}</label>
                <input type="number" class="form-control" min="1" max="99" step="1" data-field="quantity" value="1">
            </div>
        </div>
        <div class="wf-field-group" data-role="wf-payment-amount-fields" hidden>
            <div class="wf-field">
                <label class="wf-field__label">{{ __('automations.v2.payment_form.amount') }} <span data-role="wf-currency"></span></label>
                <input type="text" inputmode="decimal" class="form-control" data-field="amount" placeholder="250.00">
            </div>
        </div>
    </div>
    <div class="wf-field">
        <p class="wf-help mb-0">{{ __('automations.v2.payment_form.email_note') }}</p>
    </div>
</template>
