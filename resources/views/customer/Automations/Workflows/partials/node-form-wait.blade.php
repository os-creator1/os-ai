{{--
    Automations V2 (contract §12, V2-D) — Wait form. `until_business_hours`
    is an explicit non-goal (§12) and is never offered.
--}}
<template id="wf-node-form-wait">
    <fieldset class="wf-field">
        <legend class="wf-field__label">{{ __('automations.v2.wait_form.mode') }}</legend>
        <div class="wf-segmented">
            <label class="wf-segmented__option">
                <input type="radio" name="wf-wait-mode" value="duration" checked>
                <span>{{ __('automations.v2.wait_form.mode_duration') }}</span>
            </label>
            <label class="wf-segmented__option">
                <input type="radio" name="wf-wait-mode" value="until_datetime">
                <span>{{ __('automations.v2.wait_form.mode_until_datetime') }}</span>
            </label>
        </div>
    </fieldset>

    <div class="row g-2 wf-field" data-role="wf-wait-duration-fields">
        <div class="col-5">
            <label class="wf-field__label">{{ __('automations.v2.wait_form.amount') }}</label>
            <input type="number" class="form-control" min="1" data-field="amount">
        </div>
        <div class="col-7">
            <label class="wf-field__label">{{ __('automations.v2.wait_form.unit') }}</label>
            <select class="form-select" data-field="unit">
                <option value="minutes">{{ __('automations.v2.wait_form.unit_minutes') }}</option>
                <option value="hours">{{ __('automations.v2.wait_form.unit_hours') }}</option>
                <option value="days" selected>{{ __('automations.v2.wait_form.unit_days') }}</option>
            </select>
        </div>
    </div>

    <div class="wf-field" data-role="wf-wait-datetime-fields" hidden>
        <label class="wf-field__label">{{ __('automations.v2.wait_form.at') }}</label>
        <input type="datetime-local" class="form-control" data-field="at">
        <p class="wf-help mb-0">{{ __('automations.v2.wait_form.at_help') }}</p>
    </div>
</template>
