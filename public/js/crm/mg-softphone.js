/*
 * MotionGrove CRM softphone (browser calling).
 *
 * How a call works (all server-checked; this file only drives the browser side):
 *   1. Ask for the microphone (so a refusal is reported before anything is dialled).
 *   2. POST browser-session -> a short-lived Telnyx login token for THIS user (never the API key or a SIP password).
 *   3. Log the Telnyx WebRTC client in and wait until it is registered.
 *   4. POST calls {channel: "browser"} -> the server rings THIS browser first. The SDK reports an incoming call; we
 *      answer it (only while a call we just requested is pending).
 *   5. Once the browser has answered, the server dials the contact and, when the contact answers, bridges the two
 *      legs: that bridge is the audio. We poll the call's status to show connecting / ringing / connected / ended.
 * Mute and hang up act on the live SDK call; hang up also tells the server so both legs are closed.
 */
(function () {
    'use strict';

    var root = document.getElementById('mg-softphone');
    if (!root) { return; }

    var cfg;
    try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { return; }

    var $ = function (role) {
        var sel = '[data-role="' + role + '"]';
        return root.querySelector(sel) || document.querySelector('[data-role="softphone-pill"] ' + sel) || document.querySelector('[data-role="softphone-pill"]' + sel);
    };
    var statusEl = $('softphone-status');
    var detailEl = $('softphone-detail');
    var timerEl = $('softphone-timer');
    var muteBtn = $('softphone-mute');
    var muteLabelEl = $('softphone-mute-label');
    var hangBtn = $('softphone-hangup');
    var hangLabelEl = $('softphone-hangup-label');
    var closeBtn = $('softphone-close');
    var minBtn = $('softphone-minimize');
    var controlsEl = $('softphone-controls');
    var hangRow = $('softphone-hangup-row');
    var doneRow = $('softphone-done-row');
    var summaryEl = $('softphone-summary');
    var subEl = $('softphone-sub');
    var pill = $('softphone-pill');
    var pillTimer = $('pill-timer');
    var pillMute = $('pill-mute');

    // Functions that are NOT implemented yet. Their controls exist in the markup as hidden hooks
    // ([data-capability]) and stay hidden until a real implementation sets the flag to true.
    var capabilities = { keypad: false, hold: false, record: false, transfer: false, note: false, incoming: false };
    root.querySelectorAll('[data-capability]').forEach(function (el) {
        el.hidden = !capabilities[el.getAttribute('data-capability')];
    });
    var minimized = false;
    var audioEl = document.getElementById('mg-remote-audio');

    var client = null;
    var sdkCall = null;
    var expecting = false;
    var callUid = null;
    var pollTimer = null;
    var tickTimer = null;
    var connectedAt = null;
    var active = false;
    var finished = false;
    var muted = false;
    var attempt = 0;
    var starting = false;   // true while a start sequence (microphone -> session -> place) is still on the network
    var reloadTimer = null;
    var CANCELLED = { cancelled: true };

    var MESSAGES = {
        calling_unavailable: 'Calling is not available for this business right now.',
        browser_calling_unavailable: 'Browser calling is not available for this business right now.',
        number_not_voice_ready: 'This business does not have a number set up for calling yet.',
        contact_blocked: 'This number is on the do-not-contact list.',
        contact_not_found: 'This contact could not be found.',
        invalid_destination: 'This contact does not have a valid phone number.',
        too_many_active_calls: 'Too many calls are in progress. Try again shortly.',
        already_on_a_call: 'You are already on a call.',
        contact_call_in_progress: 'A call to this contact is already in progress.',
        rate_limited: 'Hourly call limit reached.',
        browser_session_required: 'The calling session expired. Try again.',
        browser_not_answered: 'Your browser did not connect the call. Check your microphone and try again.',
        browser_disconnected: 'Your browser disconnected before the call connected.',
        bridge_failed: 'The call could not be connected.',
        contact_not_dialable: 'The contact could not be dialled.'
    };

    function say(code, fallback) {
        if (MESSAGES[code]) { return MESSAGES[code]; }
        if (code && /^http_/.test(code)) { return 'The call could not be placed. Please try again.'; }
        return fallback || 'Something went wrong. Please try again.';
    }

    function show() { root.classList.add('is-open'); applyMinimized(); }

    function isLive(state) { return state === 'connecting' || state === 'ringing' || state === 'connected'; }

    // Minimize swaps the card for the pill; it never touches the call itself. Only a live call can be minimized.
    function applyMinimized() {
        var open = root.classList.contains('is-open');
        var min = open && minimized && isLive(root.getAttribute('data-state'));
        root.hidden = !open || min;
        pill.hidden = !min;
        root.setAttribute('data-minimized', min ? '1' : '0');
    }

    function setState(state, text, detail) {
        root.setAttribute('data-state', state);
        statusEl.textContent = text;
        detailEl.textContent = detail || '';
        detailEl.hidden = !detail;
        var live = isLive(state);
        if (!live) { minimized = false; }
        hangRow.hidden = !live;
        hangLabelEl.textContent = state === 'connected' ? 'End call' : 'Cancel';
        controlsEl.hidden = !live;
        muteBtn.disabled = state !== 'connected';
        pillMute.disabled = state !== 'connected';
        minBtn.hidden = !live;
        closeBtn.hidden = live;
        doneRow.hidden = live;
        summaryEl.hidden = state !== 'ended' && state !== 'failed';
        timerEl.hidden = state !== 'connected' && state !== 'ended';
        subEl.textContent = (state === 'ended' || state === 'failed') ? 'Outgoing call' : (cfg.contactPhone || '') + ' \u00b7 Outgoing';
        applyMinimized();
    }

    function fmt(seconds) {
        seconds = Math.max(0, Math.round(seconds));
        var m = Math.floor(seconds / 60), s = seconds % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function showTime(text) { timerEl.textContent = text; pillTimer.textContent = text; }

    function showSummary(outcome, label, seconds) {
        var out = $('summary-outcome');
        out.textContent = label;
        out.setAttribute('data-outcome', outcome);
        $('summary-duration').textContent = fmt(seconds || 0);
        $('summary-direction').textContent = 'Outgoing';
    }

    function startTimer() {
        if (tickTimer) { return; }
        connectedAt = Date.now();
        tickTimer = setInterval(function () { showTime(fmt((Date.now() - connectedAt) / 1000)); }, 500);
        showTime('00:00');
    }

    function api(method, url, body, keepalive) {
        var options = {
            method: method,
            credentials: 'same-origin',
            keepalive: !!keepalive,
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' }
        };
        if (body) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (json) {
                return { ok: response.ok, status: response.status, json: json };
            });
        });
    }

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
        return 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    }

    function cleanup() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
        expecting = false;
        try { if (sdkCall && sdkCall.state !== 'hangup' && sdkCall.state !== 'destroy') { sdkCall.hangup(); } } catch (e) {}
        try { if (client) { client.disconnect(); } } catch (e) {}
        sdkCall = null;
        client = null;
        active = false;
        muted = false;
        renderMute();
    }

    function fail(message) {
        if (finished) { return; }
        finished = true;
        cleanup();
        show();
        showSummary('failed', 'Failed', 0);
        setState('failed', 'Call failed', message);
    }

    function finishFromServer(call) {
        if (finished) { return; }
        finished = true;
        var seconds = call.duration_seconds;
        cleanup();
        if (call.status === 'failed') {
            showSummary('failed', 'Failed', seconds || 0);
            setState('failed', 'Call failed', say(call.failure_code));
            refreshHistory();
            return;
        }
        var connected = call.answered_at !== null;
        showSummary(connected ? 'connected' : 'unanswered', connected ? 'Connected' : 'Not answered', connected ? seconds || 0 : 0);
        setState('ended', 'Call ended', '');
        showTime(fmt(connected ? seconds || 0 : 0));
        timerEl.hidden = !connected;
        refreshHistory();
    }

    function refreshHistory() {
        var holder = document.querySelector('[data-role="contact-call-history"]');
        if (holder && holder.getAttribute('data-reload') === '1') {
            if (reloadTimer) { clearTimeout(reloadTimer); }
            // Never reload the page out from under a call the user has started in the meantime.
            reloadTimer = setTimeout(function () { if (!active && !starting) { window.location.reload(); } }, 1200);
        }
    }

    function applyServerState(call) {
        if (finished) { return; }
        switch (call.ui_state) {
            case 'connecting': setState('connecting', 'Connecting…', 'Setting up your call.'); break;
            case 'ringing': setState('ringing', 'Ringing…', 'Calling ' + cfg.contactLabel + '.'); break;
            case 'connected': setState('connected', 'Connected', 'You are speaking with ' + cfg.contactLabel + '.'); startTimer(); break;
            default: finishFromServer(call);
        }
    }

    function poll() {
        if (!callUid || finished) { return; }
        var issuedAttempt = attempt, issuedUid = callUid;
        api('GET', cfg.statusUrl.replace('__CALL__', encodeURIComponent(callUid))).then(function (r) {
            // A response that outlived its call must never act on a newer one the user has since started.
            if (issuedAttempt !== attempt || issuedUid !== callUid) { return; }
            if (r.ok && r.json && r.json.data) { applyServerState(r.json.data); }
        }).catch(function () { /* a missed poll is retried on the next tick */ });
    }

    function onNotification(n) {
        if (!n || n.type !== 'callUpdate' || !n.call) { return; }
        var call = n.call;

        if (call.state === 'ringing') {
            if (expecting && !sdkCall) {
                sdkCall = call;
                expecting = false;
                try { call.answer(); } catch (e) { fail('Your browser could not answer the call. Check your microphone and try again.'); }
            } else if (call !== sdkCall) {
                try { call.hangup(); } catch (e) {} // never answer a call we did not ask for
            }
            return;
        }

        if (call === sdkCall && (call.state === 'hangup' || call.state === 'destroy')) { poll(); }
    }

    function waitForReady(rtc) {
        return new Promise(function (resolve, reject) {
            var timer = setTimeout(function () { reject(new Error('timeout')); }, 15000);
            rtc.on('telnyx.ready', function () { clearTimeout(timer); resolve(); });
            rtc.on('telnyx.error', function () { clearTimeout(timer); reject(new Error('error')); });
            rtc.on('telnyx.socket.error', function () { clearTimeout(timer); reject(new Error('socket')); });
        });
    }

    function start() {
        if (active) { show(); return; }
        if (starting) {
            // A cancelled attempt is still unwinding (e.g. an open microphone prompt): do not overlap a new one with it.
            show();
            setState('idle', 'Please wait', 'Finishing the previous attempt…');
            return;
        }
        if (reloadTimer) { clearTimeout(reloadTimer); reloadTimer = null; }
        starting = true;
        active = true;
        finished = false;
        var mine = ++attempt;
        // A hang-up/cancel (or a newer attempt) invalidates every step of this one still waiting on the network.
        var stale = function () { return mine !== attempt || finished; };
        var rtc = null; // this attempt's SDK instance: a stale step may only ever tear down its own
        callUid = null;
        connectedAt = null;
        showTime('00:00');
        minimized = false;
        show();
        setState('connecting', 'Allow microphone access', 'Your browser will ask to use the microphone.');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.TelnyxWebRTC) {
            fail('This browser cannot make calls. Use a current version of Chrome, Edge, Firefox or Safari.');
            return;
        }

        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            if (stale()) { throw CANCELLED; }
            setState('connecting', 'Connecting…', 'Starting your calling session.');
            return api('POST', cfg.sessionUrl);
        }).then(function (r) {
            if (stale()) { throw CANCELLED; }
            if (!r || !r.ok || !r.json || !r.json.data) {
                throw { friendly: say(r && r.json && r.json.error && r.json.error.code) };
            }
            client = rtc = new window.TelnyxWebRTC.TelnyxRTC({ login_token: r.json.data.login_token });
            client.remoteElement = 'mg-remote-audio'; // the <audio> element id, as the SDK documents
            client.on('telnyx.notification', onNotification);
            var ready = waitForReady(client);
            client.connect();
            return ready;
        }).then(function () {
            if (stale()) { try { rtc.disconnect(); } catch (e) {} if (client === rtc) { client = null; } throw CANCELLED; }
            expecting = true;
            setState('connecting', 'Connecting…', 'Calling ' + cfg.contactLabel + ' from your business number.');
            return api('POST', cfg.placeUrl, { contact_uid: cfg.contactUid, idempotency_key: uuid(), channel: 'browser' });
        }).then(function (r) {
            if (!r.ok || !r.json || !r.json.data) {
                if (stale()) { throw CANCELLED; }
                throw { friendly: say(r.json && r.json.error && r.json.error.code, r.json && r.json.error && r.json.error.message) };
            }
            if (stale()) {
                // The user cancelled while the call was being placed: end whatever the server just started.
                api('POST', cfg.hangupUrl.replace('__CALL__', encodeURIComponent(r.json.data.id))).catch(function () {});
                throw CANCELLED;
            }
            callUid = r.json.data.id;
            if (r.json.data.ui_state === 'failed') { return finishFromServer(r.json.data); }
            pollTimer = setInterval(poll, 1500);
            poll();
        }).catch(function (e) {
            if (e === CANCELLED) { return; }
            if (stale()) { return; }
            if (e && e.name === 'NotAllowedError') { return fail('Microphone access was blocked. Allow it in your browser settings and try again.'); }
            if (e && e.name === 'NotFoundError') { return fail('No microphone was found.'); }
            if (e && e.friendly) { return fail(e.friendly); }
            fail('Could not start the call. Check your connection and try again.');
        }).then(function () { starting = false; });
    }

    function hangUp() {
        if (finished || !active) { return; }
        var uid = callUid, issuedAttempt = attempt;
        try { if (sdkCall) { sdkCall.hangup(); } } catch (e) {}
        if (uid) {
            api('POST', cfg.hangupUrl.replace('__CALL__', encodeURIComponent(uid))).then(function (r) {
                if (issuedAttempt !== attempt) { return; }
                if (r.ok && r.json && r.json.data) { return finishFromServer(r.json.data); }
                finishFromServer({ status: 'ended', answered_at: connectedAt ? 'x' : null, duration_seconds: connectedAt ? (Date.now() - connectedAt) / 1000 : 0 });
            }).catch(function () {
                if (issuedAttempt !== attempt) { return; }
                finishFromServer({ status: 'ended', answered_at: connectedAt ? 'x' : null, duration_seconds: connectedAt ? (Date.now() - connectedAt) / 1000 : 0 });
            });
        } else {
            finished = true;
            attempt++; // abandon the start sequence still waiting on the microphone prompt or the network
            cleanup();
            showSummary('unanswered', 'Cancelled', 0);
            setState('ended', 'Call cancelled', '');
            timerEl.hidden = true;
        }
    }

    function toggleMute() {
        if (!sdkCall) { return; }
        try {
            if (muted) { sdkCall.unmuteAudio(); } else { sdkCall.muteAudio(); }
            muted = !muted;
        } catch (e) { return; }
        renderMute();
    }

    function renderMute() {
        var label = muted ? muteBtn.getAttribute('data-label-unmute') : muteBtn.getAttribute('data-label-mute');
        muteLabelEl.textContent = label;
        muteBtn.classList.toggle('on', muted);
        muteBtn.setAttribute('aria-pressed', muted ? 'true' : 'false');
        pillMute.classList.toggle('on', muted);
        pillMute.setAttribute('aria-pressed', muted ? 'true' : 'false');
        pillMute.setAttribute('aria-label', label);
    }

    document.querySelectorAll('[data-role="contact-call"]').forEach(function (button) {
        button.addEventListener('click', start);
    });
    muteBtn.addEventListener('click', toggleMute);
    hangBtn.addEventListener('click', hangUp);
    pillMute.addEventListener('click', toggleMute);
    $('pill-hangup').addEventListener('click', hangUp);
    $('pill-expand').addEventListener('click', function () { minimized = false; applyMinimized(); });
    minBtn.addEventListener('click', function () { minimized = true; applyMinimized(); });
    function closePopup() { minimized = false; root.classList.remove('is-open'); applyMinimized(); }
    closeBtn.addEventListener('click', closePopup);
    $('softphone-done').addEventListener('click', closePopup);
    // "Call again" reuses the exact same start sequence (and its server checks) as the contact's call button.
    $('softphone-again').addEventListener('click', start);

    // Leaving the page must not leave a call (or a charge) running on the server.
    window.addEventListener('pagehide', function () {
        if (active && !finished && callUid) {
            api('POST', cfg.hangupUrl.replace('__CALL__', encodeURIComponent(callUid)), null, true);
        }
    });
})();
