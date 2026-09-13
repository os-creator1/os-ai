{{--
    Automations V2 (contract §12, V2-D) — Wait drawer. `until_business_hours`
    is an explicit non-goal (§12) and is never offered.
--}}
<template id="wf-node-form-wait">
    <div class="mb-3">
        <label class="form-label">{{ __('automations.v2.wait_form.mode') }}</label>
        <select class="form-select" data-field="mode" data-role="wf-wait-mode">
            <option value="duration">{{ __('automations.v2.wait_form.mode_duration') }}</option>
            <option value="until_datetime">{{ __('automations.v2.wait_form.mode_until_datetime') }}</option>
        </select>
    </div>

    <div class="mb-3 row g-2" data-role="wf-wait-duration-fields">
        <div class="col-6">
            <label class="form-label">{{ __('automations.v2.wait_form.amount') }}</label>
            <input type="number" class="form-control" min="1" data-field="amount">
        </div>
        <div class="col-6">
            <label class="form-label">{{ __('automations.v2.wait_form.unit') }}</label>
            <select class="form-select" data-field="unit">
                <option value="minutes">{{ __('automations.v2.wait_form.unit_minutes') }}</option>
                <option value="hours">{{ __('automations.v2.wait_form.unit_hours') }}</option>
                <option value="days" selected>{{ __('automations.v2.wait_form.unit_days') }}</option>
            </select>
        </div>
    </div>

    <div class="mb-0 d-none" data-role="wf-wait-datetime-fields">
        <label class="form-label">{{ __('automations.v2.wait_form.at') }}</label>
        <input type="datetime-local" class="form-control" data-field="at">
    </div>
</template>
