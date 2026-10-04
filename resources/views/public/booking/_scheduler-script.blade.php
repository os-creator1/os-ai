{{--
    Public scheduler behaviour. Presentation and state only: which dates are
    open and which times are offered always come from the server (the dates and
    slots endpoints), never from arithmetic in the browser. The one thing the
    browser decides is the visitor's own timezone, which is sent as a display
    hint and can never move a slot.

    Latest request wins: every dates/slots call aborts its predecessor and a
    response that is no longer the newest is discarded.
--}}
<script>
(function () {
    'use strict';

    var app = document.getElementById('pb-app');
    if (!app) { return; }
    var cfg = JSON.parse(app.getAttribute('data-config'));
    var $ = function (id) { return document.getElementById(id); };
    var els = {
        month: $('pb-month'), prev: $('pb-prev'), next: $('pb-next'), dow: $('pb-dow'), days: $('pb-days'),
        slots: $('pb-slots'), timesTitle: $('pb-times-title'), tz: $('pb-tz'), tzLabel: $('pb-tz-label'),
        form: $('pb-form'), picked: $('pb-picked'), alert: $('pb-alert'), submit: $('pb-submit'),
        summary: $('pb-summary'), notice: $('pb-notice'), gcal: $('pb-gcal'), ics: $('pb-ics'), status: $('pb-status'),
        backTime: $('pb-back-time'), backDate: $('pb-back-date')
    };
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    var state = {
        tz: cfg.businessTimezone, month: null, today: null, from: null, to: null,
        date: null, slot: null, slots: [], available: {}, notice: null, submitting: false
    };
    var seq = { dates: 0, slots: 0 };
    var aborters = { dates: null, slots: null };

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function setStep(step) { app.setAttribute('data-step', step); }
    function say(text) { els.status.textContent = text; }

    function zoneIsListed(zone) {
        for (var i = 0; i < els.tz.options.length; i++) { if (els.tz.options[i].value === zone) { return true; } }
        return false;
    }

    function todayIn(zone) {
        try {
            var p = new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
            return p;
        } catch (e) { return null; }
    }

    function longDate(ymd) {
        var p = ymd.split('-');
        return new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
            .format(new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])));
    }

    function get(kind, url) {
        seq[kind] += 1;
        var mine = seq[kind];
        if (aborters[kind]) { aborters[kind].abort(); }
        var ctrl = typeof AbortController === 'function' ? new AbortController() : null;
        aborters[kind] = ctrl;
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
            .then(function (res) {
                if (!res.ok) { throw new Error('http ' + res.status); }
                return res.json();
            })
            .then(function (data) { return { fresh: mine === seq[kind], data: data }; });
    }

    /* ---------- calendar ---------- */

    function renderDow() {
        els.dow.innerHTML = '';
        DOW.forEach(function (d) { var s = document.createElement('div'); s.className = 'pb-dow'; s.textContent = d; els.dow.appendChild(s); });
    }

    function renderMonth(loading) {
        var parts = state.month.split('-');
        var y = +parts[0], m = +parts[1];
        els.month.textContent = MONTHS[m - 1] + ' ' + y;
        els.days.innerHTML = '';
        els.days.classList.toggle('is-loading', !!loading);
        var firstDow = new Date(Date.UTC(y, m - 1, 1)).getUTCDay();
        var count = new Date(Date.UTC(y, m, 0)).getUTCDate();
        for (var i = 0; i < firstDow; i++) { els.days.appendChild(document.createElement('span')); }
        var open = state.available[state.tz + '|' + state.month] || [];
        for (var d = 1; d <= count; d++) {
            var ymd = state.month + '-' + pad(d);
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'pb-day';
            b.textContent = d;
            b.setAttribute('data-date', ymd);
            var isOpen = open.indexOf(ymd) !== -1;
            if (isOpen) { b.className += ' is-open'; b.setAttribute('aria-label', longDate(ymd) + ', times available'); }
            else { b.disabled = true; b.setAttribute('aria-label', longDate(ymd) + ', unavailable'); }
            if (ymd === state.today) { b.className += ' is-today'; }
            if (ymd === state.date) { b.className += ' is-selected'; b.setAttribute('aria-pressed', 'true'); }
            els.days.appendChild(b);
        }
        var cur = state.today ? state.today.slice(0, 7) : state.month;
        els.prev.disabled = state.month <= cur;
        els.next.disabled = state.to ? state.month >= state.to.slice(0, 7) : false;
    }

    function loadMonth(month) {
        state.month = month;
        var key = state.tz + '|' + month;
        if (state.available[key]) { renderMonth(false); return; }
        renderMonth(true);
        get('dates', cfg.datesUrl + '?month=' + encodeURIComponent(month) + '&tz=' + encodeURIComponent(state.tz))
            .then(function (r) {
                if (!r.fresh) { return; }
                state.today = r.data.today; state.from = r.data.from; state.to = r.data.to;
                state.available[r.data.timezone + '|' + r.data.month] = r.data.available;
                if (r.data.timezone === state.tz && r.data.month === state.month) { renderMonth(false); }
            })
            .catch(function (e) {
                if (e && e.name === 'AbortError') { return; }
                els.days.classList.remove('is-loading');
                say('Could not load dates. Please try again.');
            });
    }

    function shiftMonth(delta) {
        var p = state.month.split('-');
        var d = new Date(Date.UTC(+p[0], +p[1] - 1 + delta, 1));
        loadMonth(d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1));
    }

    /* ---------- times ---------- */

    function renderSkeleton() {
        els.slots.innerHTML = '';
        for (var i = 0; i < 4; i++) { var s = document.createElement('div'); s.className = 'pb-skeleton'; els.slots.appendChild(s); }
    }

    function renderSlots() {
        els.slots.innerHTML = '';
        if (state.notice) {
            var n = document.createElement('p');
            n.className = 'pb-alert'; n.setAttribute('role', 'alert'); n.textContent = state.notice;
            els.slots.appendChild(n);
            state.notice = null;
        }
        els.timesTitle.textContent = state.date ? longDate(state.date).replace(/, \d{4}$/, '') : 'Available times';
        if (!state.slots.length) {
            var p = document.createElement('p');
            p.className = 'pb-empty';
            p.textContent = 'No available times on this date. Try another date.';
            els.slots.appendChild(p);
            return;
        }
        state.slots.forEach(function (slot, index) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'pb-slot' + (state.slot && state.slot.start === slot.start ? ' is-chosen' : '');
            b.textContent = slot.label;
            b.setAttribute('data-index', index);
            els.slots.appendChild(b);
        });
    }

    function selectDate(ymd) {
        state.date = ymd;
        state.slot = null;
        state.slots = [];
        renderMonth(false);
        setStep('time');
        renderSkeleton();
        say('Loading times for ' + longDate(ymd));
        get('slots', cfg.slotsUrl + '?date=' + encodeURIComponent(ymd) + '&tz=' + encodeURIComponent(state.tz))
            .then(function (r) {
                if (!r.fresh || state.date !== ymd) { return; }
                state.slots = r.data.slots;
                renderSlots();
                say(r.data.slots.length + ' times available');
            })
            .catch(function (e) {
                if (e && e.name === 'AbortError') { return; }
                els.slots.innerHTML = '<p class="pb-empty">Could not load times. Please try again.</p>';
            });
    }

    function selectSlot(slot) {
        state.slot = slot;
        els.form.elements.date.value = slot.date;
        var t = els.form.querySelector('input[name="time"]');
        if (!t) {
            t = document.createElement('input');
            t.type = 'hidden'; t.name = 'time';
            els.form.appendChild(t);
        }
        t.value = slot.time;
        els.form.elements.visitor_timezone.value = state.tz;
        els.picked.hidden = false;
        els.picked.innerHTML = '';
        var strong = document.createElement('span');
        strong.textContent = slot.label + ' – ' + slot.end_label + ', ' + longDate(state.date);
        var small = document.createElement('small');
        small.textContent = cfg.typeName + ' · ' + state.tz.replace(/_/g, ' ');
        els.picked.appendChild(strong);
        els.picked.appendChild(small);
        clearErrors();
        setStep('details');
        els.form.elements.first_name.focus();
    }

    /* ---------- form ---------- */

    function clearErrors() {
        els.alert.hidden = true;
        var errs = els.form.parentNode.querySelectorAll('[data-err]');
        Array.prototype.forEach.call(errs, function (e) { e.hidden = true; e.textContent = ''; });
    }

    function showErrors(errors, message) {
        clearErrors();
        var shown = false;
        Object.keys(errors || {}).forEach(function (key) {
            var target = els.form.parentNode.querySelector('[data-err="' + key + '"]');
            if (target) { target.textContent = errors[key][0]; target.hidden = false; shown = true; }
        });
        if (!shown || message) {
            els.alert.textContent = message || 'Please check your details.';
            els.alert.hidden = false;
        }
    }

    function icsEscape(text) { return String(text).replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\n/g, '\\n'); }
    function compact(iso) { return iso.replace(/[-:]/g, '').replace(/\.\d+/, ''); }

    function showConfirmation(b) {
        els.summary.innerHTML = '';
        if (els.notice) { els.notice.textContent = b.notice || ''; els.notice.hidden = !b.notice; }
        [['What', b.type], ['Who', b.business], ['When', b.date], ['Time', b.time], ['Time zone', b.timezone.replace(/_/g, ' ')], ['Where', b.where], ['Details', b.instructions]]
            .forEach(function (row) {
                if (!row[1]) { return; }
                var dt = document.createElement('dt'); dt.textContent = row[0];
                var dd = document.createElement('dd'); dd.textContent = row[1];
                els.summary.appendChild(dt); els.summary.appendChild(dd);
            });
        var title = b.type + ' with ' + b.business;
        els.gcal.href = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' + encodeURIComponent(title)
            + '&dates=' + compact(b.start) + '/' + compact(b.end) + (b.where ? '&location=' + encodeURIComponent(b.where) : '');
        var ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Business OS//Booking//EN', 'BEGIN:VEVENT',
            'UID:' + compact(b.start) + '-' + Math.random().toString(36).slice(2) + '@@booking',
            'DTSTAMP:' + compact(new Date().toISOString()), 'DTSTART:' + compact(b.start), 'DTEND:' + compact(b.end),
            'SUMMARY:' + icsEscape(title)];
        if (b.where) { ics.push('LOCATION:' + icsEscape(b.where)); }
        ics.push('END:VEVENT', 'END:VCALENDAR');
        els.ics.href = URL.createObjectURL(new Blob([ics.join('\r\n')], { type: 'text/calendar' }));
        setStep('done');
        say('Your booking is confirmed.');
        window.scrollTo(0, 0);
    }

    function submit(ev) {
        ev.preventDefault();
        if (state.submitting || !state.slot) { return; }
        state.submitting = true;
        els.submit.disabled = true;
        clearErrors();
        fetch(cfg.storeUrl, {
            method: 'POST', body: new FormData(els.form), credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': '*/*' }
        }).then(function (res) {
            return res.json().then(function (data) { return { status: res.status, data: data }; });
        }).then(function (r) {
            state.submitting = false;
            els.submit.disabled = false;
            if (r.status === 201) { state.available = {}; showConfirmation(r.data.booking); return; }
            if (r.status === 409) {
                // The slot went while the guest typed: refresh the day and send them back to pick another.
                state.available = {};
                state.slot = null;
                setStep('time');
                state.notice = 'That time was just taken. Please choose another.';
                loadMonth(state.month);
                selectDate(state.date);
                return;
            }
            showErrors(r.data.errors, r.data.errors && r.data.errors.time ? r.data.errors.time[0] : null);
        }).catch(function () {
            state.submitting = false;
            els.submit.disabled = false;
            showErrors({}, 'Something went wrong. Please try again.');
        });
    }

    /* ---------- wiring ---------- */

    els.days.addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('.pb-day.is-open') : null;
        if (b) { selectDate(b.getAttribute('data-date')); }
    });
    els.slots.addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('.pb-slot') : null;
        if (b) { selectSlot(state.slots[+b.getAttribute('data-index')]); }
    });
    els.prev.addEventListener('click', function () { shiftMonth(-1); });
    els.next.addEventListener('click', function () { shiftMonth(1); });
    els.backDate.addEventListener('click', function () { setStep('date'); });
    els.backTime.addEventListener('click', function () { setStep('time'); renderSlots(); });
    els.form.addEventListener('submit', submit);
    els.tz.addEventListener('change', function () {
        state.tz = els.tz.value;
        els.tzLabel.textContent = state.tz;
        state.slot = null;
        if (app.getAttribute('data-step') === 'details') { setStep('time'); }
        loadMonth(state.month);
        if (state.date) { selectDate(state.date); }
    });

    /* ---------- start ---------- */

    renderDow();
    var detected = null;
    try { detected = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) { detected = null; }
    if (detected && zoneIsListed(detected)) { state.tz = detected; els.tz.value = detected; els.tzLabel.textContent = detected; }
    // Without a server-rendered day the times list starts empty until a date is chosen.
    if (!els.form.elements.date.value) { els.slots.innerHTML = ''; }
    state.today = todayIn(state.tz);
    state.date = null;
    // Radios rendered for the no-script flow are replaced by buttons.
    Array.prototype.forEach.call(els.slots.querySelectorAll('.pb-slot-fallback'), function (n) { n.parentNode.removeChild(n); });
    els.slots.innerHTML = '';
    loadMonth((state.today || new Date().toISOString().slice(0, 10)).slice(0, 7));
})();
</script>
