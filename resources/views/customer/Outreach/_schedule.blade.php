{{--
    Shared campaign-builder schedule + advanced block. Param: $idSuffix.
    Intended to be @included inside the caller's own <div class="row">...
    </div> — this partial is fully self-contained (no unbalanced tags).
--}}
<div class="col-12">
    <div class="mb-1">
        <div class="form-check form-check-inline">
            <input type="checkbox" id="schedule-{{ $idSuffix }}" class="form-check-input" value="true" name="schedule" data-role="schedule-toggle" {{ old('schedule') ? 'checked' : null }}>
            <label class="form-check-label" for="schedule-{{ $idSuffix }}">{{ __('locale.campaigns.schedule_campaign') }}?</label>
        </div>
        <p><small class="text-primary px-2">{{ __('locale.campaigns.schedule_campaign_note') }}</small></p>
    </div>
</div>

<div class="col-12">
    <div class="row" data-role="schedule-time">
        <div class="col-md-6">
            <div class="mb-1">
                <label for="schedule_date-{{ $idSuffix }}" class="form-label">{{ __('locale.labels.date') }}</label>
                <input type="text" id="schedule_date-{{ $idSuffix }}" name="schedule_date" class="form-control" data-role="schedule-date" placeholder="YYYY-MM-DD" />
                @error('schedule_date')
                <p><small class="text-danger">{{ $message }}</small></p>
                @enderror
            </div>
        </div>

        <div class="col-md-6">
            <div class="mb-1">
                <label for="time-{{ $idSuffix }}" class="form-label">{{ __('locale.labels.time') }}</label>
                <input type="text" id="time-{{ $idSuffix }}" class="form-control text-start" data-role="schedule-flatpickr-time" name="schedule_time" placeholder="HH:MM" />
                @error('schedule_time')
                <p><small class="text-danger">{{ $message }}</small></p>
                @enderror
            </div>
        </div>

        <div class="col-12">
            <div class="mb-1">
                <label for="timezone-{{ $idSuffix }}" class="form-label">{{ __('locale.labels.timezone') }}</label>
                <select class="form-select select2" id="timezone-{{ $idSuffix }}" name="timezone">
                    @foreach(\App\Library\Tool::allTimeZones() as $timezone)
                        <option value="{{ $timezone['zone'] }}" {{ Auth::user()->timezone == $timezone['zone'] ? 'selected' : null }}>{{ $timezone['text'] }}</option>
                    @endforeach
                </select>
                @error('timezone')
                <p><small class="text-danger">{{ $message }}</small></p>
                @enderror
            </div>
        </div>

        <div class="col-12">
            <div class="mb-1">
                <label for="frequency_cycle-{{ $idSuffix }}" class="form-label">{{ __('locale.labels.frequency') }}</label>
                <select class="form-select" id="frequency_cycle-{{ $idSuffix }}" name="frequency_cycle" data-role="frequency-cycle">
                    <option value="onetime">{{ __('locale.labels.one_time') }}</option>
                    <option value="daily">{{ __('locale.labels.daily') }}</option>
                    <option value="monthly">{{ __('locale.labels.monthly') }}</option>
                    <option value="yearly">{{ __('locale.labels.yearly') }}</option>
                    <option value="custom">{{ __('locale.labels.custom') }}</option>
                </select>
            </div>
            @error('frequency_cycle')
            <p><small class="text-danger">{{ $message }}</small></p>
            @enderror
        </div>

        <div class="col-sm-6 col-12" data-role="show-custom">
            <div class="mb-1">
                <label for="frequency_amount-{{ $idSuffix }}" class="form-label">{{ __('locale.plans.frequency_amount') }}</label>
                <input type="text" id="frequency_amount-{{ $idSuffix }}" class="form-control text-right @error('frequency_amount') is-invalid @enderror" name="frequency_amount" value="{{ old('frequency_amount') }}">
                @error('frequency_amount')
                <p><small class="text-danger">{{ $message }}</small></p>
                @enderror
            </div>
        </div>

        <div class="col-sm-6 col-12" data-role="show-custom">
            <div class="mb-1">
                <label for="frequency_unit-{{ $idSuffix }}" class="form-label">{{ __('locale.plans.frequency_unit') }}</label>
                <select class="form-select" id="frequency_unit-{{ $idSuffix }}" name="frequency_unit">
                    <option value="day">{{ __('locale.labels.day') }}</option>
                    <option value="week">{{ __('locale.labels.week') }}</option>
                    <option value="month">{{ __('locale.labels.month') }}</option>
                    <option value="year">{{ __('locale.labels.year') }}</option>
                </select>
            </div>
            @error('frequency_unit')
            <p><small class="text-danger">{{ $message }}</small></p>
            @enderror
        </div>

        <div class="col-md-6" data-role="show-recurring">
            <div class="mb-1">
                <label for="recurring_date-{{ $idSuffix }}" class="form-label">{{ __('locale.labels.end_date') }}</label>
                <input type="text" id="recurring_date-{{ $idSuffix }}" name="recurring_date" class="form-control" data-role="schedule-date" placeholder="YYYY-MM-DD" />
                @error('recurring_date')
                <p><small class="text-danger">{{ $message }}</small></p>
                @enderror
            </div>
        </div>

        <div class="col-md-6" data-role="show-recurring">
            <div class="mb-1">
                <label for="recurring_time-{{ $idSuffix }}" class="form-label">{{ __('locale.labels.end_time') }}</label>
                <input type="text" id="recurring_time-{{ $idSuffix }}" class="form-control text-start" data-role="schedule-flatpickr-time" name="recurring_time" placeholder="HH:MM" />
                @error('recurring_time')
                <p><small class="text-danger">{{ $message }}</small></p>
                @enderror
            </div>
        </div>
    </div>
</div>

<div class="col-12">
    <div class="mb-1">
        <div class="form-check form-check-inline">
            <input type="checkbox" id="advanced-{{ $idSuffix }}" name="advanced" class="form-check-input" value="true" data-role="advanced-toggle">
            <label class="form-check-label" for="advanced-{{ $idSuffix }}">{{ __('locale.labels.advanced') }}</label>
        </div>
    </div>
</div>

<div class="col-12">
    <div class="row" data-role="advanced-fields">
        <div class="col-12">
            <div class="mb-1">
                <div class="form-check form-check-inline">
                    <input type="checkbox" id="send_copy-{{ $idSuffix }}" value="true" name="send_copy" class="form-check-input">
                    <label class="form-check-label" for="send_copy-{{ $idSuffix }}">{{ __('locale.campaigns.send_copy_via_email') }}</label>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="mb-1">
                <div class="form-check form-check-inline">
                    <input type="checkbox" id="create_template-{{ $idSuffix }}" value="true" name="create_template" class="form-check-input">
                    <label class="form-check-label" for="create_template-{{ $idSuffix }}">{{ __('locale.campaigns.create_template_based_message') }}</label>
                </div>
            </div>
        </div>
    </div>
</div>
