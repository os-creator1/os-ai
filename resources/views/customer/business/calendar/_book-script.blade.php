<script>
(function () {
    'use strict';

    var form = document.querySelector('[data-section="appointment-form"]');
    if (!form) { return; }

    var cfg = JSON.parse(form.getAttribute('data-booking-config'));
    var state = cfg.state;
    var types = {};
    cfg.types.forEach(function (t) { types[t.uid] = t; });

    var $ = function (sel) { return form.querySelector(sel); };
    var daysEl = $('[data-role="days"]');
    var timesEl = $('[data-role="times"]');
    var weekLabel = $('[data-role="week-label"]');
    var prev = $('[data-action="week-prev"]');
    var dateField = $('[data-field="date"]');
    var timeField = $('[data-field="time"]');
    var submit = $('[data-role="book-appointment"]');
    var hint = $('[data-role="book-hint"]');
    var staffSel = $('#staff');
    var whereSel = $('#meeting_location_type');
    var slots = Array.prototype.map.call(timesEl.querySelectorAll('[data-time]'), function (b) {
        return { time: b.getAttribute('data-time'), label: b.getAttribute('data-label') };
    });
    var hasStaff = true;
    var loading = false;
    var token = 0;

    function utc(date) { var p = date.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
    function fmt(date, opts) { return utc(date).toLocaleDateString('en-US', Object.assign({ timeZone: 'UTC' }, opts)); }
    function addDays(date, n) { var d = utc(date); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); }
    function text(el, v) { if (el) { el.textContent = v; } }
    function make(tag, cls, label) { var e = document.createElement(tag); e.className = cls || ''; if (label) { e.textContent = label; } return e; }

    function timeLabel() {
        for (var i = 0; i < slots.length; i++) { if (slots[i].time === state.time) { return slots[i].label; } }
        return null;
    }

    function renderSummary() {
        var type = types[state.type];
        var label = state.time ? timeLabel() : null;
        text($('[data-sum="when"]'), label ? fmt(state.date, { weekday: 'short', month: 'short', day: 'numeric' }) + ' · ' + label : 'Pick a time');
        text($('[data-sum="what"]'), type.name + ' · ' + type.minutes + ' min');
        text($('[data-sum="with"]'), state.staff === 'auto' ? 'Assigned automatically' : (cfg.staff[state.staff] || 'Assigned automatically'));
        var override = whereSel && whereSel.value;
        var where = type.where;
        if (override) {
            // The labels are the select's own option texts, so only locations this business offers can appear.
            where = override === 'custom'
                ? (form.querySelector('[name="meeting_location_custom"]').value.trim() || 'Custom location')
                : whereSel.options[whereSel.selectedIndex].text;
        }
        text($('[data-sum="where"]'), where);
        timeField.value = label ? state.time : '';
        dateField.value = state.date;
        submit.disabled = !label;
        if (hint) { hint.hidden = !!label; }
        Array.prototype.forEach.call(form.querySelectorAll('.bk-ty'), function (el) {
            el.classList.toggle('on', el.querySelector('input').value === state.type);
        });
    }

    function renderDays(days) {
        daysEl.textContent = '';
        days.forEach(function (d) {
            var b = make('button', 'bk-dy' + (d.date === state.date ? ' on' : ''));
            b.type = 'button';
            b.setAttribute('data-date', d.date);
            b.appendChild(document.createTextNode(d.weekday));
            b.appendChild(make('b', '', d.day));
            if (!d.available && d.date !== state.date) { b.disabled = true; }
            daysEl.appendChild(b);
        });
        var first = days[0].date, last = days[6].date;
        var a = utc(first), z = utc(last);
        text(weekLabel, fmt(first, { month: 'short', day: 'numeric' }) + ' – ' + (a.getUTCMonth() === z.getUTCMonth() ? z.getUTCDate() : fmt(last, { month: 'short', day: 'numeric' })));
        state.week = first;
        prev.disabled = first <= cfg.thisMonday;
    }

    function renderTimes() {
        timesEl.textContent = '';
        if (loading) {
            timesEl.appendChild(make('div', 'bk-times-empty', 'Loading times…'));
            return;
        }
        if (!slots.length) {
            timesEl.appendChild(make('div', 'bk-times-empty', hasStaff ? 'No open times on this day. Pick another day.' : 'Nobody is assigned to this appointment type yet, so there is nothing to book.'));
            return;
        }
        slots.forEach(function (s) {
            var b = make('button', 'bk-tm' + (s.time === state.time ? ' on' : ''), s.label);
            b.type = 'button';
            b.setAttribute('data-time', s.time);
            b.setAttribute('data-label', s.label);
            timesEl.appendChild(b);
        });
    }

    // Ask the server what the canonical availability pair offers for the current choice.
    function load(keepTime) {
        var mine = ++token;
        var q = new URLSearchParams({ booking_type_uid: state.type, staff: state.staff, date: state.date, week: state.week || state.date });
        // Until the answer arrives nothing from the previous choice may be picked.
        loading = true; slots = []; state.time = null;
        timesEl.setAttribute('aria-busy', 'true');
        renderTimes();
        renderSummary();
        return fetch(cfg.slotsUrl + '?' + q.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('slots ' + r.status); } return r.json(); })
            .then(function (data) {
                if (mine !== token) { return; }   // a newer choice superseded this answer
                loading = false;
                slots = data.slots;
                hasStaff = data.has_staff;
                if (!keepTime || !slots.some(function (s) { return s.time === state.time; })) { state.time = null; }
                renderDays(data.days);
                renderTimes();
                renderSummary();
            })
            .catch(function () {
                if (mine !== token) { return; }
                loading = false;
                slots = []; state.time = null; hasStaff = true;
                timesEl.textContent = '';
                timesEl.appendChild(make('div', 'bk-times-empty', 'Times could not be loaded. Reload the page and try again.'));
                renderSummary();
            })
            .then(function () { if (mine === token) { timesEl.removeAttribute('aria-busy'); } });
    }

    form.addEventListener('change', function (e) {
        if (e.target.name === 'booking_type_uid') {
            state.type = e.target.value;
            state.time = null;
            renderSummary();
            load(false);
        } else if (e.target.id === 'staff') {
            state.staff = e.target.value;
            state.time = null;
            renderSummary();
            load(false);
        } else if (e.target.id === 'meeting_location_type') {
            renderSummary();
        }
    });
    form.addEventListener('input', function (e) {
        if (e.target.name === 'meeting_location_custom') { renderSummary(); }
    });

    form.addEventListener('click', function (e) {
        var day = e.target.closest('.bk-dy');
        var time = e.target.closest('.bk-tm');
        var nav = e.target.closest('.bk-nav');

        if (day && !day.disabled) {
            state.date = day.getAttribute('data-date');
            state.time = null;
            renderSummary();
            load(false);
        } else if (time) {
            state.time = time.getAttribute('data-time');
            Array.prototype.forEach.call(timesEl.querySelectorAll('.bk-tm'), function (b) { b.classList.toggle('on', b === time); });
            renderSummary();
        } else if (nav && !nav.disabled) {
            var step = nav.getAttribute('data-action') === 'week-next' ? 7 : -7;
            state.week = addDays(state.week, step);
            // Browsing weeks moves the selection to the same weekday of that week, so the
            // chosen day is always one of the seven shown.
            state.date = addDays(state.date, step);
            state.time = null;
            renderSummary();
            load(false);
        }
    });

    form.addEventListener('submit', function (e) {
        if (!state.time) { e.preventDefault(); }
    });

    renderSummary();
})();
</script>
