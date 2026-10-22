{{--
    The real MotionGrove public scheduler (same engine, endpoints and script as /book/{uuid}), dressed for the
    Book a Demo card. The visitor's contact answers were collected in step 1 and are appended to the booking
    write by book-demo.js, so this form only asks for the time and the bot check.
--}}
<section class="pb-app" id="pb-app" data-role="public-scheduler" data-step="date"
         data-config="{{ json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}">
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
            @foreach ($timezones as $region => $zones)
                <optgroup label="{{ $region }}">
                    @foreach ($zones as $zone)
                        <option value="{{ $zone }}" @selected($zone === $timezone)>{{ str_replace('_', ' ', $zone) }}</option>
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
        <form method="post" id="pb-form" action="{{ $demo['storeUrl'] }}" novalidate>
            @csrf
            <input type="hidden" name="date" value="">
            <input type="hidden" name="visitor_timezone" value="{{ $timezone }}">
            <input type="hidden" name="form_version" value="{{ $formVersion }}">
            {{-- The booking's own essentials, asked only for what the questionnaire does not already collect. --}}
            @if (! empty($attendeeNeeds['first_name']))
            <label class="f" for="attendee-first-name" data-attendee="first_name" style="margin-bottom:14px">First name<input id="attendee-first-name" type="text" name="attendee_first_name" autocomplete="given-name" maxlength="120" required><span class="f-err" data-err="attendee_first_name" role="alert" hidden></span></label>
            @endif
            @if (! empty($attendeeNeeds['email']))
            <label class="f" for="attendee-email" data-attendee="email" style="margin-bottom:16px">Email<input id="attendee-email" type="email" name="attendee_email" autocomplete="email" maxlength="190" required><span class="f-err" data-err="attendee_email" role="alert" hidden></span></label>
            @endif
            {{-- Honeypot: invisible to people, irresistible to form-filling bots. --}}
            <div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label for="demo-hp">Leave this field empty</label><input id="demo-hp" type="text" name="{{ $demo['honeypot'] }}" value="" tabindex="-1" autocomplete="off"></div>
            {{-- Rendered by book-demo.js only when this step opens, so the token is always fresh. --}}
            @if ($demo['turnstileSiteKey'])<div id="mgd-turnstile" data-sitekey="{{ $demo['turnstileSiteKey'] }}" style="margin:6px 0 14px"></div>@endif
            <p class="pb-err" data-err="turnstile" hidden></p>
            <p class="pb-err" data-err="time" hidden></p>
            <button type="submit" class="pb-btn" id="pb-submit">Confirm booking</button>
        </form>
    </div>

    <div class="pb-done" data-role="confirmation" id="pb-done" aria-live="polite">
        <div class="pb-check"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 10.5 3.5 3.5 7.5-8"/></svg></div>
        <h3 class="pb-heading">You are booked</h3>
        <p style="margin:0;color:var(--pb-muted)">Your demo is confirmed. Keep this page for your details.</p>
        <p id="pb-notice" data-role="booking-notice" style="margin:8px 0 0;color:var(--pb-muted)" hidden></p>
        <div class="pb-summary"><dl id="pb-summary"></dl></div>
        <div class="pb-actions">
            <a class="pb-btn is-ghost" id="pb-gcal" target="_blank" rel="noopener">Add to Google Calendar</a>
            <a class="pb-btn is-ghost" id="pb-ics" download="appointment.ics">Download .ics</a>
        </div>
    </div>
    <div class="pb-sr" role="status" aria-live="polite" id="pb-status"></div>
</section>
