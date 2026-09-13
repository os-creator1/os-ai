{{--
    Automations V2 (contract §10.1, V2-D) — Send SMS drawer. Body only: v2
    resolves the sending path at execution time (managed identity, else the
    active BYO channel), so there is no sender/channel picker to persist
    here — persisting one would go stale or cross Businesses (§10.1).
--}}
<template id="wf-node-form-send_sms">
    <div class="mb-2">
        <label class="form-label">{{ __('automations.v2.send_sms_form.body') }}</label>
        <textarea class="form-control" rows="5" maxlength="1600" data-field="body"></textarea>
        <p class="text-caption mt-1">{{ __('automations.v2.send_sms_form.body_hint') }}</p>
    </div>
</template>
