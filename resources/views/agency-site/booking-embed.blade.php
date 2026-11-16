{{--
    The embeddable booking calendar: the real public scheduler (public.booking.* endpoints and _scheduler-script) for ONE
    booking type, without the organizer / meeting-summary sidebar. Month navigation, real available dates, times for the
    chosen date, time-zone selection and booking completion are all the existing engine's; this file is markup only.
    Contact details are asked after a time is picked (the booking needs them), never before.
--}}
<style>
    /* The scheduler's field styles are scoped to its own page card (.pb-card); the embed has no such card, so the same rules apply to .pb-app. */
    .pb-app input[type=text], .pb-app input[type=email], .pb-app input[type=tel], .pb-app select {
        width: 100%; box-sizing: border-box; padding: 11px 12px; border: 1px solid #cfcac1; border-radius: 12px; background: #fff; color: var(--pb-ink, #181413); font: inherit;
    }
    .pb-app input:focus, .pb-app select:focus { outline: 2px solid var(--pb-accent, #B4514A); outline-offset: 0; border-color: transparent; }
</style>
<section class="pb-app" id="pb-app" data-role="public-scheduler" data-step="date"
         data-config="{{ json_encode($embed['config'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}">
    <p style="margin: 0 0 14px; color: var(--pb-muted, #5E5652); font-size: 14px">Times are shown in <span id="pb-tz-label">{{ $embed['timezone'] }}</span>.</p>
    <div class="pb-stage">
        <div class="pb-calendar" data-role="calendar">
            <div class="pb-cal-head">
                <span class="pb-month" id="pb-month" aria-live="polite"></span>
                <span class="pb-nav">
                    <button type="button" class="pb-icon-btn" id="pb-prev" aria-label="Previous month"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg></button>
                    <button type="button" class="pb-icon-btn" id="pb-next" aria-label="Next month"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.5 4.5 13 10l-5.5 5.5"/></svg></button>
                </span>
            </div>
            <div class="pb-grid" id="pb-dow" aria-hidden="true"></div>
            <div class="pb-grid" id="pb-days" role="grid" aria-labelledby="pb-month"></div>
        </div>
        <div class="pb-times" data-role="times">
            <button type="button" class="pb-back pb-back-date" id="pb-back-date"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg>Choose another date</button>
            <p class="pb-times-title" id="pb-times-title">Available times</p>
            <div id="pb-slots" class="pb-slot-list" aria-live="polite"></div>
        </div>
    </div>

    <div class="pb-tz" data-role="timezone">
        <label for="pb-tz">Time zone</label>
        <select id="pb-tz" aria-label="Time zone">
            @foreach ($embed['timezones'] as $region => $zones)
                <optgroup label="{{ $region }}">
                    @foreach ($zones as $zone)
                        <option value="{{ $zone }}" @selected($zone === $embed['timezone'])>{{ str_replace('_', ' ', $zone) }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    </div>

    <div class="pb-details" data-role="details">
        <button type="button" class="pb-back" id="pb-back-time"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg>Back</button>
        <h3 class="pb-heading">Confirm your time</h3>
        <p class="pb-picked" id="pb-picked" hidden></p>
        <p class="pb-alert" id="pb-alert" role="alert" hidden></p>
        <form method="post" id="pb-form" action="{{ $embed['config']['storeUrl'] }}" novalidate>
            @csrf
            <input type="hidden" name="date" value="">
            <input type="hidden" name="visitor_timezone" value="{{ $embed['timezone'] }}">
            <div class="pb-row">
                <div class="pb-field"><label for="first-name">First name</label><input id="first-name" type="text" name="first_name" autocomplete="given-name" required><p class="pb-err" data-err="first_name" hidden></p></div>
                <div class="pb-field"><label for="last-name">Last name</label><input id="last-name" type="text" name="last_name" autocomplete="family-name" required><p class="pb-err" data-err="last_name" hidden></p></div>
            </div>
            <div class="pb-field"><label for="email">Email</label><input id="email" type="email" name="email" autocomplete="email" inputmode="email" required><p class="pb-err" data-err="email" hidden></p></div>
            <div class="pb-field"><label for="phone">Phone</label><input id="phone" type="tel" name="phone" autocomplete="tel" inputmode="tel" required><p class="pb-err" data-err="phone" hidden></p></div>
            @if ($embed['offerSmsConsent'])
                <div class="pb-field"><label for="sms-consent" style="font-weight:400"><input id="sms-consent" type="checkbox" name="sms_consent" value="1" style="width:auto;margin-right:8px">Text me the confirmation and reminders for this appointment. Message and data rates may apply. This is only for this booking and is not marketing consent.</label></div>
            @endif
            <p class="pb-err" data-err="time" hidden></p>
            <button type="submit" class="pb-btn" id="pb-submit">Confirm booking</button>
        </form>
    </div>

    <div class="pb-done" data-role="confirmation" id="pb-done" aria-live="polite">
        <div class="pb-check"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 10.5 3.5 3.5 7.5-8"/></svg></div>
        <h3 class="pb-heading">You are booked</h3>
        <p style="margin:0;color:var(--pb-muted)">Your appointment is confirmed. Keep this page for your details.</p>
        <p id="pb-notice" data-role="booking-notice" style="margin:8px 0 0;color:var(--pb-muted)" hidden></p>
        <div class="pb-summary"><dl id="pb-summary"></dl></div>
        <div class="pb-actions">
            <a class="pb-btn is-ghost" id="pb-gcal" target="_blank" rel="noopener">Add to Google Calendar</a>
            <a class="pb-btn is-ghost" id="pb-ics" download="appointment.ics">Download .ics</a>
        </div>
    </div>
    <div class="pb-sr" role="status" aria-live="polite" id="pb-status"></div>
</section>
