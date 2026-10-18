{{--
    Automations — Send booking link form. One of this Business's booking types
    (catalogs.bookingTypes, grouped by Location), then how to deliver the link. The
    link is the Calendar's own public booking page; a workflow limited to a Location
    can send only that Location's booking types.
--}}
<template id="wf-node-form-send_booking_link">
    <div class="wf-field">
        <label class="wf-field__label">{{ __('automations.v2.booking_form.booking_type') }}</label>
        <select class="form-select" data-field="booking_type_id" data-role="wf-booking-type-select"></select>
        <p class="wf-help" data-role="wf-no-booking-types" hidden>{{ __('automations.v2.booking_form.no_booking_types') }}</p>
    </div>
    @include('customer.Automations.Workflows.partials._link-delivery')
</template>
