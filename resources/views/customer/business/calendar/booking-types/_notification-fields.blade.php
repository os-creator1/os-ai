{{--
    Booking Notifications V1 — the "Customer notifications" card of the Booking
    Type editor. Lives inside the editor's single form, so Save changes persists
    it with everything else. Each control maps to a real column the Calendar
    notification scheduler reads (notify_email, notify_sms, reminder_offsets).
    An unchecked box still submits (hidden 0), and the reminders section always
    submits `reminders_submitted` so removing every row means "no reminders".
--}}
@php
    $emailOn = (bool) old('notify_email', $bookingType->notifiesByEmail());
    $smsOn = (bool) old('notify_sms', $bookingType->notifiesBySms());
    $offsets = old('reminders_submitted') ? array_map('intval', (array) old('reminder_offsets', [])) : $bookingType->reminderOffsetMinutes();
    $options = \App\Models\BookingType::REMINDER_OFFSET_OPTIONS;
    $maxReminders = \App\Models\BookingType::MAX_REMINDERS;
@endphp

<x-card title="Customer notifications" class="mb-2" data-section="booking-type-notifications">
    <p class="text-caption">
        Sent to the customer after they book and shortly before the appointment. Times are in the
        business time zone. They include the date, time, location and your meeting instructions, plus a calendar link.
    </p>

    <h3 class="text-label mb-50">Confirmation</h3>
    <input type="hidden" name="notify_email" value="0">
    <div class="form-check mb-50">
        <input class="form-check-input" type="checkbox" id="notify_email" name="notify_email" value="1" @checked($emailOn)>
        <label class="form-check-label" for="notify_email">Send by email</label>
    </div>
    @if ($emailOn && ! $notificationReadiness['email']['ready'])
        <p class="text-caption text-danger" data-notification-warning="email">Email is not ready: {{ $notificationReadiness['email']['reason'] }}</p>
    @endif

    <input type="hidden" name="notify_sms" value="0">
    <div class="form-check mb-50">
        <input class="form-check-input" type="checkbox" id="notify_sms" name="notify_sms" value="1" @checked($smsOn)>
        <label class="form-check-label" for="notify_sms">Send by text message</label>
    </div>
    <p class="text-caption mb-1">
        Text messages go only to customers who tick the text-message consent box on the booking page.
        That consent covers this appointment's messages only — it is not marketing consent.
    </p>
    @if ($smsOn && ! $notificationReadiness['sms']['ready'])
        <p class="text-caption text-danger" data-notification-warning="sms">
            Text messages are not ready: {{ $notificationReadiness['sms']['reason'] }}
            Bookings still go through; customers just will not be texted.
        </p>
    @elseif (! $smsOn && ! $notificationReadiness['sms']['ready'])
        <p class="text-caption" data-notification-hint="sms">Text messages are not set up yet: {{ $notificationReadiness['sms']['reason'] }}</p>
    @endif

    <h3 class="text-label mb-50 mt-1">Reminders</h3>
    <input type="hidden" name="reminders_submitted" value="1">
    <div id="reminder-rows" data-max="{{ $maxReminders }}">
        @foreach ($offsets as $offset)
            <div class="d-flex align-items-center mb-50" data-reminder-row>
                <select name="reminder_offsets[]" class="form-control mr-1" style="max-width:260px" aria-label="Reminder timing">
                    @foreach ($options as $value => $label)
                        <option value="{{ $value }}" @selected((int) $offset === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-remove-reminder>Remove</button>
            </div>
        @endforeach
    </div>
    <p class="text-caption" id="reminder-empty" @if ($offsets !== []) hidden @endif>No reminders. Customers get the confirmation only.</p>
    <button type="button" class="btn btn-sm btn-outline-primary" id="add-reminder">Add reminder</button>
    <small class="text-caption d-block mt-50">
        Reminders use the same channels as the confirmation. Changes apply to bookings made after you save.
        A reminder is never sent for a time that has already passed, a cancelled appointment, or a time the appointment has moved away from.
    </small>

    <template id="reminder-row-template">
        <div class="d-flex align-items-center mb-50" data-reminder-row>
            <select name="reminder_offsets[]" class="form-control mr-1" style="max-width:260px" aria-label="Reminder timing">
                @foreach ($options as $value => $label)
                    <option value="{{ $value }}" @selected($value === 1440)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-remove-reminder>Remove</button>
        </div>
    </template>
</x-card>

<script>
    (function () {
        var rows = document.getElementById('reminder-rows');
        var add = document.getElementById('add-reminder');
        var empty = document.getElementById('reminder-empty');
        var tpl = document.getElementById('reminder-row-template');
        if (!rows || !add || !tpl) { return; }
        var max = parseInt(rows.getAttribute('data-max'), 10) || 4;

        function sync() {
            var count = rows.querySelectorAll('[data-reminder-row]').length;
            add.disabled = count >= max;
            empty.hidden = count > 0;
        }
        add.addEventListener('click', function () {
            if (rows.querySelectorAll('[data-reminder-row]').length >= max) { return; }
            rows.appendChild(tpl.content.cloneNode(true));
            sync();
        });
        rows.addEventListener('click', function (event) {
            var button = event.target.closest('[data-remove-reminder]');
            if (!button) { return; }
            button.closest('[data-reminder-row]').remove();
            sync();
        });
        sync();
    })();
</script>
