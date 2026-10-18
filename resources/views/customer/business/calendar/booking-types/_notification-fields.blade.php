{{--
    Calendar Notifications V2 — the "Customer notifications" card of the Booking
    Type editor. It lives inside the editor's single form, so Save changes persists
    it with everything else. Reminders are an ordered list of steps: any timing
    (minutes, hours or days before), email, text or both, each with its own wording
    that starts from a default. The confirmation wording is editable the same way.
--}}
@php
    $emailOn = (bool) old('notify_email', $bookingType->notifiesByEmail());
    $smsOn = (bool) old('notify_sms', $bookingType->notifiesBySms());
    $defaults = \App\Library\Calendar\Notifications\AppointmentNotificationContent::defaults();
    $variables = \App\Library\Calendar\Notifications\AppointmentNotificationContent::VARIABLES;
    $unitFor = function (int $minutes): array {
        if ($minutes % 1440 === 0) { return [intdiv($minutes, 1440), 'days']; }
        if ($minutes % 60 === 0) { return [intdiv($minutes, 60), 'hours']; }

        return [$minutes, 'minutes'];
    };
    $steps = collect($bookingType->reminderSteps())->map(function (array $s) use ($unitFor) {
        [$value, $unit] = $unitFor((int) $s['offset_minutes']);

        return $s + ['value' => $value, 'unit' => $unit];
    })->values()->all();
    if (old('reminder_steps_submitted')) {
        $steps = collect((array) old('reminder_steps', []))->map(fn ($s) => [
            'id' => $s['id'] ?? '', 'value' => $s['value'] ?? 1, 'unit' => $s['unit'] ?? 'hours',
            'email' => ! empty($s['email']), 'sms' => ! empty($s['sms']), 'enabled' => ! isset($s['enabled']) || (bool) $s['enabled'],
            'email_subject' => $s['email_subject'] ?? '', 'email_body' => $s['email_body'] ?? '', 'sms_body' => $s['sms_body'] ?? '',
        ])->values()->all();
    }
    $previewUrl = route('customer.workspaces.businesses.calendar.booking-types.notification-preview', $withType ?? [$workspace->uid, $business->uid, $location->uid, $bookingType->uid]);
    $editorData = [
        'steps' => $steps,
        'defaults' => $defaults,
        'previewUrl' => $previewUrl,
        'max' => \App\Models\BookingType::MAX_REMINDERS,
    ];
@endphp

<section class="cx-card" data-section="booking-type-notifications">
    <div class="cx-card-head is-plain">
        <div>
            <h2 class="cx-card-title">Customer notifications</h2>
            <p class="cx-card-sub">
                Sent to the customer after they book and before the appointment. Times are in the business time zone.
                Messages include the date, time, location and your meeting instructions, plus a calendar link, unless you write your own wording.
            </p>
        </div>
    </div>
    <div class="cx-card-body">
        <h3 class="cx-meta-label mb-50">Confirmation</h3>

        <div class="bt-notify-option">
            <input type="hidden" name="notify_email" value="0">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="notify_email" name="notify_email" value="1" @checked($emailOn)>
                <label class="form-check-label" for="notify_email">Send by email</label>
            </div>
            @if ($emailOn && ! $notificationReadiness['email']['ready'])
                <div class="cx-callout is-danger" data-notification-warning="email">
                    <x-ds-icon name="alert-triangle" size="15" aria-hidden="true" />
                    <span class="cx-callout-text">Email is not ready: {{ $notificationReadiness['email']['reason'] }}</span>
                </div>
            @endif
        </div>

        <div class="bt-notify-option">
            <input type="hidden" name="notify_sms" value="0">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="notify_sms" name="notify_sms" value="1" @checked($smsOn)>
                <label class="form-check-label" for="notify_sms">Send by text message</label>
            </div>
            <p class="cx-hint mb-0">
                Text messages go only to customers who tick the text-message consent box on the booking page.
                That consent covers this appointment's messages only — it is not marketing consent.
            </p>
            @if ($smsOn && ! $notificationReadiness['sms']['ready'])
                <div class="cx-callout is-danger" data-notification-warning="sms">
                    <x-ds-icon name="alert-triangle" size="15" aria-hidden="true" />
                    <span class="cx-callout-text">
                        Text messages are not ready: {{ $notificationReadiness['sms']['reason'] }}
                        Bookings still go through; customers just will not be texted.
                    </span>
                </div>
            @elseif (! $smsOn && ! $notificationReadiness['sms']['ready'])
                <div class="cx-callout is-warning" data-notification-hint="sms">
                    <x-ds-icon name="alert-circle" size="15" aria-hidden="true" />
                    <span class="cx-callout-text">Text messages are not set up yet: {{ $notificationReadiness['sms']['reason'] }}</span>
                </div>
            @endif
        </div>

        <details class="mt-1" data-role="confirmation-wording">
            <summary class="cx-meta-label">Confirmation wording <span class="text-caption">(optional — leave blank to use the default)</span></summary>
            <div class="mt-50">
                <label class="form-label" for="confirmation_email_subject">Email subject</label>
                <input type="text" class="form-control" id="confirmation_email_subject" name="confirmation_email_subject" maxlength="200"
                       value="{{ old('confirmation_email_subject', $bookingType->confirmation_email_subject) }}" placeholder="{{ $defaults['confirmation_email_subject'] }}">
                <label class="form-label mt-50" for="confirmation_email_body">Email message</label>
                <textarea class="form-control" rows="5" id="confirmation_email_body" name="confirmation_email_body" maxlength="3000"
                          placeholder="{{ $defaults['confirmation_email_body'] }}">{{ old('confirmation_email_body', $bookingType->confirmation_email_body) }}</textarea>
                <label class="form-label mt-50" for="confirmation_sms_body">Text message</label>
                <textarea class="form-control" rows="2" id="confirmation_sms_body" name="confirmation_sms_body" maxlength="480"
                          placeholder="{{ $defaults['confirmation_sms_body'] }}">{{ old('confirmation_sms_body', $bookingType->confirmation_sms_body) }}</textarea>
                <p class="cx-hint mb-0">Text messages always end with “Reply STOP to opt out.”</p>
                <div class="mt-50">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-preview="confirmation" data-channel="email">Preview email</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-preview="confirmation" data-channel="sms">Preview text</button>
                </div>
                <pre class="border rounded p-1 mt-50 mb-0" data-role="preview-confirmation" hidden style="white-space: pre-wrap;"></pre>
            </div>
        </details>

        <h3 class="cx-meta-label mb-50 mt-2">Reminders</h3>
        <input type="hidden" name="reminders_submitted" value="1">
        <input type="hidden" name="reminder_steps_submitted" value="1">
        <div id="reminder-rows"></div>
        @error('reminder_steps') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        @foreach ($errors->get('reminder_steps.*') as $messages)
            @foreach ($messages as $message)<div class="invalid-feedback d-block">{{ $message }}</div>@endforeach
        @endforeach
        <p class="text-caption" id="reminder-empty" hidden>No reminders. Customers get the confirmation only.</p>
        <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" id="add-reminder">
            <x-ds-icon name="plus" size="14" aria-hidden="true" />Add reminder
        </button>

        <p class="cx-hint mt-1 mb-25">Variables you can use in any wording:</p>
        <p class="mb-0">
            @foreach ($variables as $key => $label)
                <code class="me-1" title="{{ $label }}">&#123;&#123;{{ $key }}&#125;&#125;</code>
            @endforeach
        </p>
        <small class="cx-hint mt-50">
            A reminder is never sent for a time that has already passed, a cancelled appointment, or a time the appointment has moved away from.
            Changes apply to reminders that have not been sent yet.
        </small>
    </div>
</section>

<script>
    (function () {
        var data = @json($editorData);
        var rows = document.getElementById('reminder-rows');
        var add = document.getElementById('add-reminder');
        var empty = document.getElementById('reminder-empty');
        var section = document.querySelector('[data-section="booking-type-notifications"]');
        if (!rows || !add || !section) { return; }
        var steps = data.steps.slice();
        var csrf = document.querySelector('input[name="_token"]');

        function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
        function render() {
            rows.innerHTML = '';
            steps.forEach(function (s, i) {
                var n = 'reminder_steps[' + i + ']';
                var el = document.createElement('div');
                el.className = 'border rounded p-1 mb-1';
                el.setAttribute('data-reminder-row', '');
                el.innerHTML =
                    '<input type="hidden" name="' + n + '[id]" value="' + esc(s.id) + '">' +
                    '<div class="d-flex flex-wrap gap-1 align-items-end">' +
                    '<div><label class="form-label">Send</label>' +
                    '<input type="number" min="1" class="form-control" style="width:6rem" name="' + n + '[value]" value="' + esc(s.value) + '"></div>' +
                    '<div><label class="form-label">&nbsp;</label><select class="form-control" name="' + n + '[unit]">' +
                    ['minutes', 'hours', 'days'].map(function (u) { return '<option value="' + u + '"' + (s.unit === u ? ' selected' : '') + '>' + u + ' before</option>'; }).join('') +
                    '</select></div>' +
                    '<div class="form-check"><input type="hidden" name="' + n + '[email]" value="0"><input class="form-check-input" type="checkbox" name="' + n + '[email]" value="1"' + (s.email ? ' checked' : '') + '><label class="form-check-label">Email</label></div>' +
                    '<div class="form-check"><input type="hidden" name="' + n + '[sms]" value="0"><input class="form-check-input" type="checkbox" name="' + n + '[sms]" value="1"' + (s.sms ? ' checked' : '') + '><label class="form-check-label">Text</label></div>' +
                    '<div class="form-check"><input type="hidden" name="' + n + '[enabled]" value="0"><input class="form-check-input" type="checkbox" name="' + n + '[enabled]" value="1"' + (s.enabled ? ' checked' : '') + '><label class="form-check-label">Enabled</label></div>' +
                    '<span class="ms-auto">' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="up"' + (i === 0 ? ' disabled' : '') + '>Up</button> ' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="down"' + (i === steps.length - 1 ? ' disabled' : '') + '>Down</button> ' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-remove-reminder data-act="del">Remove</button></span>' +
                    '</div>' +
                    '<details class="mt-50"><summary class="text-caption">Wording (optional — blank uses the default)</summary>' +
                    '<label class="form-label mt-50">Email subject</label><input type="text" class="form-control" maxlength="200" name="' + n + '[email_subject]" value="' + esc(s.email_subject) + '" placeholder="' + esc(data.defaults.reminder_email_subject) + '">' +
                    '<label class="form-label mt-50">Email message</label><textarea class="form-control" rows="4" maxlength="3000" name="' + n + '[email_body]" placeholder="' + esc(data.defaults.reminder_email_body) + '">' + esc(s.email_body) + '</textarea>' +
                    '<label class="form-label mt-50">Text message</label><textarea class="form-control" rows="2" maxlength="480" name="' + n + '[sms_body]" placeholder="' + esc(data.defaults.reminder_sms_body) + '">' + esc(s.sms_body) + '</textarea>' +
                    '<div class="mt-50"><button type="button" class="btn btn-sm btn-outline-secondary" data-preview="reminder" data-channel="email">Preview email</button> ' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-preview="reminder" data-channel="sms">Preview text</button></div>' +
                    '<pre class="border rounded p-1 mt-50 mb-0" data-role="preview-step" hidden style="white-space: pre-wrap;"></pre>' +
                    '</details>';
                rows.appendChild(el);
            });
            add.disabled = steps.length >= data.max;
            empty.hidden = steps.length > 0;
        }
        function read() {
            return Array.prototype.map.call(rows.querySelectorAll('[data-reminder-row]'), function (r) {
                var f = function (k) { var x = r.querySelector('[name$="[' + k + ']"]'); return x ? x : null; };
                var chk = function (k) { var all = r.querySelectorAll('[name$="[' + k + ']"]'); return all.length ? all[all.length - 1].checked : false; };
                return {
                    id: f('id').value, value: parseInt(f('value').value, 10) || 1, unit: f('unit').value,
                    email: chk('email'), sms: chk('sms'), enabled: chk('enabled'),
                    email_subject: f('email_subject').value, email_body: f('email_body').value, sms_body: f('sms_body').value
                };
            });
        }
        add.addEventListener('click', function () {
            if (steps.length >= data.max) { return; }
            steps = read();
            steps.push({id: '', value: 1, unit: 'days', email: true, sms: false, enabled: true, email_subject: '', email_body: '', sms_body: ''});
            render();
        });
        rows.addEventListener('click', function (e) {
            var b = e.target.closest('[data-act]');
            if (!b) { return; }
            steps = read();
            var i = Array.prototype.indexOf.call(rows.children, b.closest('[data-reminder-row]'));
            var a = b.getAttribute('data-act');
            if (a === 'del') { steps.splice(i, 1); }
            if (a === 'up' && i > 0) { steps.splice(i - 1, 0, steps.splice(i, 1)[0]); }
            if (a === 'down' && i < steps.length - 1) { steps.splice(i + 1, 0, steps.splice(i, 1)[0]); }
            render();
        });

        // Preview: the real renderer, sample appointment details.
        section.addEventListener('click', function (e) {
            var p = e.target.closest('[data-preview]');
            if (!p) { return; }
            var scope = p.closest('details');
            var channel = p.getAttribute('data-channel');
            var kind = p.getAttribute('data-preview');
            var get = function (suffix) { var el = scope.querySelector('[name$="' + suffix + '"]'); return el ? el.value : ''; };
            var body = channel === 'email'
                ? (kind === 'confirmation' ? get('confirmation_email_body') : get('[email_body]'))
                : (kind === 'confirmation' ? get('confirmation_sms_body') : get('[sms_body]'));
            var subject = kind === 'confirmation' ? get('confirmation_email_subject') : get('[email_subject]');
            var out = scope.querySelector('pre');
            fetch(data.previewUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf ? csrf.value : ''},
                body: JSON.stringify({channel: channel, kind: kind, subject: subject, body: body})
            }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
                if (!d) { return; }
                out.textContent = (d.subject ? 'Subject: ' + d.subject + '\n\n' : '') + (d.body || '');
                out.hidden = false;
            });
        });

        render();
    })();
</script>
