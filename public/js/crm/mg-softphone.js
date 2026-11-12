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

    var $ = function (role) { return root.querySelector('[data-role="' + role + '"]'); };
    var statusEl = $('softphone-status');
    var detailEl = $('softphone-detail');
    var timerEl = $('softphone-timer');
    var muteBtn = $('softphone-mute');
    var hangBtn = $('softphone-hangup');
    var closeBtn = $('softphone-close');
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

    function show() { root.hidden = false; root.classList.add('is-open'); }

    function setState(state, text, detail) {
        root.setAttribute('data-state', state);
        statusEl.textContent = text;
        detailEl.textContent = detail || '';
        var live = state === 'connecting' || state === 'ringing' || state === 'connected';
        hangBtn.hidden = !live;
        muteBtn.hidden = state !== 'connected';
        closeBtn.hidden = live;
        timerEl.hidden = state !== 'connected' && state !== 'ended';
    }

    function fmt(seconds) {
        seconds = Math.max(0, Math.round(seconds));
        var m = Math.floor(seconds / 60), s = seconds % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function startTimer() {
        if (tickTimer) { return; }
        connectedAt = Date.now();
        tickTimer = setInterval(function () { timerEl.textContent = fmt((Date.now() - connectedAt) / 1000); }, 500);
        timerEl.textContent = '0:00';
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
        muteBtn.textContent = muteBtn.getAttribute('data-label-mute');
        muteBtn.setAttribute('aria-pressed', 'false');
    }

    function fail(message) {
        if (finished) { return; }
        finished = true;
        cleanup();
        show();
        setState('failed', 'Call failed', message);
    }

    function finishFromServer(call) {
        if (finished) { return; }
        finished = true;
        var seconds = call.duration_seconds;
        cleanup();
        if (call.status === 'failed') {
            setState('failed', 'Call failed', say(call.failure_code));
            return;
        }
        var connected = call.answered_at !== null;
        setState('ended', 'Call ended', connected ? 'Duration ' + fmt(seconds || 0) + '. Saved to this contact.' : 'The call was not answered. Saved to this contact.');
        if (connected) { timerEl.textContent = fmt(seconds || 0); }
        refreshHistory();
    }

    function refreshHistory() {
        var holder = document.querySelector('[data-role="contact-call-history"]');
        if (holder && holder.getAttribute('data-reload') === '1') { setTimeout(function () { window.location.reload(); }, 1200); }
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
        api('GET', cfg.statusUrl.replace('__CALL__', encodeURIComponent(callUid))).then(function (r) {
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
        active = true;
        finished = false;
        callUid = null;
        connectedAt = null;
        timerEl.textContent = '0:00';
        show();
        setState('connecting', 'Allow microphone access', 'Your browser will ask to use the microphone.');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.TelnyxWebRTC) {
            fail('This browser cannot make calls. Use a current version of Chrome, Edge, Firefox or Safari.');
            return;
        }

        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            setState('connecting', 'Connecting…', 'Starting your calling session.');
            return api('POST', cfg.sessionUrl);
        }).then(function (r) {
            if (!r || !r.ok || !r.json || !r.json.data) {
                throw { friendly: say(r && r.json && r.json.error && r.json.error.code) };
            }
            client = new window.TelnyxWebRTC.TelnyxRTC({ login_token: r.json.data.login_token });
            client.remoteElement = 'mg-remote-audio'; // the <audio> element id, as the SDK documents
            client.on('telnyx.notification', onNotification);
            var ready = waitForReady(client);
            client.connect();
            return ready;
        }).then(function () {
            expecting = true;
            setState('connecting', 'Connecting…', 'Calling ' + cfg.contactLabel + ' from your business number.');
            return api('POST', cfg.placeUrl, { contact_uid: cfg.contactUid, idempotency_key: uuid(), channel: 'browser' });
        }).then(function (r) {
            if (!r.ok || !r.json || !r.json.data) {
                throw { friendly: say(r.json && r.json.error && r.json.error.code, r.json && r.json.error && r.json.error.message) };
            }
            callUid = r.json.data.id;
            if (r.json.data.ui_state === 'failed') { return finishFromServer(r.json.data); }
            pollTimer = setInterval(poll, 1500);
            poll();
        }).catch(function (e) {
            if (e && e.name === 'NotAllowedError') { return fail('Microphone access was blocked. Allow it in your browser settings and try again.'); }
            if (e && e.name === 'NotFoundError') { return fail('No microphone was found.'); }
            if (e && e.friendly) { return fail(e.friendly); }
            fail('Could not start the call. Check your connection and try again.');
        });
    }

    function hangUp() {
        if (finished || !active) { return; }
        var uid = callUid;
        try { if (sdkCall) { sdkCall.hangup(); } } catch (e) {}
        if (uid) {
            api('POST', cfg.hangupUrl.replace('__CALL__', encodeURIComponent(uid))).then(function (r) {
                if (r.ok && r.json && r.json.data) { return finishFromServer(r.json.data); }
                finishFromServer({ status: 'ended', answered_at: connectedAt ? 'x' : null, duration_seconds: connectedAt ? (Date.now() - connectedAt) / 1000 : 0 });
            }).catch(function () {
                finishFromServer({ status: 'ended', answered_at: connectedAt ? 'x' : null, duration_seconds: connectedAt ? (Date.now() - connectedAt) / 1000 : 0 });
            });
        } else {
            finished = true;
            cleanup();
            setState('ended', 'Call cancelled', '');
        }
    }

    function toggleMute() {
        if (!sdkCall) { return; }
        try {
            if (muted) { sdkCall.unmuteAudio(); } else { sdkCall.muteAudio(); }
            muted = !muted;
        } catch (e) { return; }
        muteBtn.textContent = muted ? muteBtn.getAttribute('data-label-unmute') : muteBtn.getAttribute('data-label-mute');
        muteBtn.setAttribute('aria-pressed', muted ? 'true' : 'false');
    }

    document.querySelectorAll('[data-role="contact-call"]').forEach(function (button) {
        button.addEventListener('click', start);
    });
    muteBtn.addEventListener('click', toggleMute);
    hangBtn.addEventListener('click', hangUp);
    closeBtn.addEventListener('click', function () { root.hidden = true; root.classList.remove('is-open'); });

    // Leaving the page must not leave a call (or a charge) running on the server.
    window.addEventListener('pagehide', function () {
        if (active && !finished && callUid) {
            api('POST', cfg.hangupUrl.replace('__CALL__', encodeURIComponent(callUid)), null, true);
        }
    });
})();
