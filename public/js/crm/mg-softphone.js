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
 *
 * INBOUND (only when the page config carries `inbound` endpoints): the page keeps ONE signed-in Telnyx client for the
 * user (shared with outbound calling), checks in with the server every 30s so it is counted as available, and when the
 * SDK reports a ring it asks the server which inbound call is ringing for this user (caller identification), shows the
 * Incoming popup, and on Answer first CLAIMS the call (one winner across tabs/users) and only then answers its leg.
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
    var nameEl = $('softphone-name');
    var avatarEl = $('softphone-avatar');
    var pillNameEl = $('pill-name');
    var pillAvatarEl = $('pill-avatar');
    var incomingRow = $('softphone-incoming');
    var againBtn = $('softphone-again');
    var inboundCfg = cfg.inbound || null;
    var direction = 'outbound';
    var sharedClient = null;      // the page's one signed-in client (inbound enabled only)
    var sharedReady = false;
    var pendingSdk = null;        // an SDK call that is ringing and has not been matched to a server call yet
    var presenceTimer = null;
    var reconnectTimer = null;
    var inboundBusy = false;      // a ring is being identified / an inbound popup is being handled
    var peerName = cfg.contactLabel || '';

    // Functions that are NOT implemented yet. Their controls exist in the markup as hidden hooks
    // ([data-capability]) and stay hidden until a real implementation sets the flag to true.
    var capabilities = { keypad: false, hold: false, record: false, transfer: false, note: false };
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

    function setIdentity(name, sub, initials) {
        peerName = name;
        nameEl.textContent = name;
        pillNameEl.textContent = name;
        avatarEl.textContent = initials;
        pillAvatarEl.textContent = initials;
        subEl.setAttribute('data-sub', sub || '');
    }

    function initialsOf(name) {
        var words = String(name || '').replace(/[^\p{L}\p{N}\s]/gu, '').trim().split(/\s+/).filter(Boolean);
        if (!words.length) { return '#'; }
        return (words[0].charAt(0) + (words.length > 1 ? words[words.length - 1].charAt(0) : '')).toUpperCase();
    }

    function dirLabel() { return direction === 'inbound' ? 'Incoming' : 'Outgoing'; }

    function setState(state, text, detail) {
        root.setAttribute('data-state', state);
        statusEl.textContent = text;
        detailEl.textContent = detail || '';
        detailEl.hidden = !detail;
        var incoming = state === 'incoming';
        var live = isLive(state);
        if (!live) { minimized = false; }
        hangRow.hidden = !live;
        hangLabelEl.textContent = state === 'connected' ? 'End call' : 'Cancel';
        incomingRow.hidden = !incoming;
        controlsEl.hidden = !live;
        muteBtn.disabled = state !== 'connected';
        pillMute.disabled = state !== 'connected';
        minBtn.hidden = !live;
        closeBtn.hidden = live || incoming;
        doneRow.hidden = live || incoming;
        againBtn.hidden = direction === 'inbound' || !cfg.contactUid;
        summaryEl.hidden = state !== 'ended' && state !== 'failed';
        timerEl.hidden = state !== 'connected' && state !== 'ended';
        var sub = subEl.getAttribute('data-sub') || '';
        subEl.textContent = (state === 'ended' || state === 'failed') ? dirLabel() + ' call' : (sub ? sub + ' \u00b7 ' : '') + dirLabel();
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
        $('summary-direction').textContent = dirLabel();
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
        try { if (client && client !== sharedClient) { client.disconnect(); } } catch (e) {}
        pendingSdk = null;
        inboundBusy = false;
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
        var missed = direction === 'inbound' ? (call.outcome === 'declined' ? 'Declined' : 'Missed') : 'Not answered';
        showSummary(connected ? 'connected' : 'unanswered', connected ? 'Connected' : missed, connected ? seconds || 0 : 0);
        setState('ended', connected || direction !== 'inbound' ? 'Call ended' : (missed + ' call'), '');
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
            case 'ringing':
                if (direction === 'inbound') { setState('connecting', 'Connecting…', 'Connecting your call.'); } else { setState('ringing', 'Ringing…', 'Calling ' + peerName + '.'); }
                break;
            case 'connected': setState('connected', 'Connected', 'You are speaking with ' + peerName + '.'); startTimer(); break;
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
            } else if (inboundCfg && call !== sdkCall) {
                onInboundRing(call);
            } else if (call !== sdkCall) {
                try { call.hangup(); } catch (e) {} // never answer a call we did not ask for
            }
            return;
        }

        if (call === pendingSdk && (call.state === 'hangup' || call.state === 'destroy')) { onPendingEnded(); return; }
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
        direction = 'outbound';
        setIdentity(cfg.contactLabel || '', cfg.contactPhone || '', cfg.contactInitials || '#');
        show();
        setState('connecting', 'Allow microphone access', 'Your browser will ask to use the microphone.');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.TelnyxWebRTC) {
            fail('This browser cannot make calls. Use a current version of Chrome, Edge, Firefox or Safari.');
            return;
        }

        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            if (stale()) { throw CANCELLED; }
            if (sharedClient && sharedReady) { return { shared: true }; }
            setState('connecting', 'Connecting…', 'Starting your calling session.');
            return api('POST', cfg.sessionUrl);
        }).then(function (r) {
            if (stale()) { throw CANCELLED; }
            if (r && r.shared) { client = rtc = sharedClient; return Promise.resolve(); }
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
            if (stale()) { try { if (rtc !== sharedClient) { rtc.disconnect(); } } catch (e) {} if (client === rtc) { client = null; } throw CANCELLED; }
            expecting = true;
            setState('connecting', 'Connecting…', 'Calling ' + peerName + ' from your business number.');
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

    // ------------------------------------------------------------------ inbound calls

    var answering = false;
    var identifyGen = 0; // invalidates an identification (and its late responses) once its ring has ended

    function onInboundRing(call) {
        // Already on / handling a call: this ring is not shown. (The server does not ring a busy user; this covers the race.)
        if (active || inboundBusy) { try { call.hangup(); } catch (e) {} return; }
        inboundBusy = true;
        pendingSdk = call;
        identifyInbound(0, ++identifyGen, call);
    }

    // The SDK knows a leg is ringing; only the server knows WHO is calling and which call it is.
    function identifyInbound(tries, gen, call) {
        var current = function () { return gen === identifyGen && pendingSdk === call; };
        if (!current()) { return; }
        var retry = function () {
            if (!current()) { return; }
            if (tries < 3) { setTimeout(function () { identifyInbound(tries + 1, gen, call); }, 1000); return; }
            // Not a call the server says is ringing for this user: never show it and never answer it.
            try { if (pendingSdk) { pendingSdk.hangup(); } } catch (e) {}
            pendingSdk = null;
            inboundBusy = false;
        };
        api('GET', inboundCfg.incomingUrl).then(function (r) {
            if (!current()) { return; } // the ring ended while we were asking: never reopen it
            var list = r.ok && r.json && Array.isArray(r.json.data) ? r.json.data : [];
            if (list.length) { showIncoming(list[0]); } else { retry(); }
        }).catch(retry);
    }

    function showIncoming(data) {
        direction = 'inbound';
        active = true;
        finished = false;
        answering = false;
        attempt++;
        callUid = data.id;
        connectedAt = null;
        var caller = data.caller || {};
        setIdentity(caller.name || caller.number || 'Unknown caller', caller.name ? (caller.number || '') : '', caller.name ? initialsOf(caller.name) : '#');
        showTime('00:00');
        minimized = false;
        show();
        setState('incoming', 'Incoming call', '');
    }

    // The ringing leg ended before we answered it: the caller gave up, it timed out, or someone else took it.
    function onPendingEnded() {
        pendingSdk = null;
        identifyGen++;
        // Still being identified (no popup yet): just free the slot so the next ring is handled.
        var waiting = root.getAttribute('data-state') === 'incoming' || answering; // ringing, or answering but not yet claimed
        if (!waiting || finished) { if (!active) { inboundBusy = false; } return; }
        var uid = callUid, mine = attempt;
        api('GET', cfg.statusUrl.replace('__CALL__', encodeURIComponent(uid))).then(function (r) {
            if (mine !== attempt || finished) { return; }
            var d = r.ok && r.json && r.json.data;
            if (d && (d.ui_state === 'ended' || d.ui_state === 'failed')) { finishFromServer(d); } else { dismissInbound(); }
        }).catch(function () { if (mine === attempt && !finished) { dismissInbound(); } });
    }

    function dismissInbound() {
        finished = true;
        cleanup();
        closePopup();
    }

    function answerInbound() {
        if (!pendingSdk || answering || finished) { return; }
        answering = true;
        var sdk = pendingSdk, uid = callUid, mine = attempt;
        setState('connecting', 'Connecting…', 'Connecting your call.');
        // The microphone is checked BEFORE claiming, so a refusal never takes the call away from everyone else.
        var mic = navigator.mediaDevices && navigator.mediaDevices.getUserMedia ? navigator.mediaDevices.getUserMedia({ audio: true }) : Promise.reject({ name: 'NotFoundError' });
        mic.then(function (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            if (mine !== attempt || finished) { throw CANCELLED; }
            return api('POST', inboundCfg.answerUrl.replace('__CALL__', encodeURIComponent(uid)));
        }).then(function (r) {
            if (mine !== attempt || finished) { throw CANCELLED; }
            if (!r.ok) {
                // Someone else answered first (or it stopped ringing): this popup simply goes away.
                var message = r.json && r.json.error && r.json.error.message;
                dismissInbound();
                throw CANCELLED;
            }
            sdkCall = sdk;
            pendingSdk = null;
            try { sdk.answer(); } catch (e) { api('POST', cfg.hangupUrl.replace('__CALL__', encodeURIComponent(uid))).catch(function () {}); fail('Your browser could not answer the call. Check your microphone and try again.'); throw CANCELLED; }
            pollTimer = setInterval(poll, 1500);
            poll();
        }).catch(function (e) {
            if (e === CANCELLED) { return; }
            // A completion that outlived its call (ended/dismissed, or replaced by a newer one) must touch nothing.
            if (mine !== attempt || finished) { return; }
            var blocked = e && e.name === 'NotAllowedError';
            declineInbound();
            finished = false;
            fail(blocked ? 'Microphone access was blocked. Allow it in your browser settings to answer calls.' : 'Could not answer the call. Check your microphone and try again.');
        });
    }

    function declineInbound() {
        var uid = callUid, sdk = pendingSdk;
        pendingSdk = null;
        var hang = function () { try { if (sdk) { sdk.hangup(); } } catch (e) {} };
        // Tell the server first so it records "declined" rather than a missed call when the leg then ends.
        if (uid && inboundCfg) { api('POST', inboundCfg.declineUrl.replace('__CALL__', encodeURIComponent(uid))).then(hang, hang); } else { hang(); }
        finished = true;
        cleanup();
        closePopup();
    }

    // ---- the page's one signed-in client + availability heartbeat

    function connectShared() {
        if (!inboundCfg || !window.TelnyxWebRTC || sharedClient) { return; }
        var rtc = null;
        api('POST', cfg.sessionUrl).then(function (r) {
            if (!r.ok || !r.json || !r.json.data) { throw new Error('session'); }
            rtc = new window.TelnyxWebRTC.TelnyxRTC({ login_token: r.json.data.login_token });
            rtc.remoteElement = 'mg-remote-audio';
            rtc.on('telnyx.notification', onNotification);
            rtc.on('telnyx.socket.close', function () { if (sharedClient === rtc) { teardownShared(); scheduleReconnect(); } });
            sharedClient = rtc;
            var ready = waitForReady(rtc);
            rtc.connect();
            return ready;
        }).then(function () {
            sharedReady = true;
            heartbeat();
            if (!presenceTimer) { presenceTimer = setInterval(heartbeat, 30000); }
            // Login tokens are short-lived: renew the session well before it lapses (never mid-call).
            setTimeout(function renew() { if (active || inboundBusy) { setTimeout(renew, 30000); return; } teardownShared(); connectShared(); }, 50 * 60 * 1000);
        }).catch(function () {
            if (rtc && sharedClient === rtc) { teardownShared(); }
            scheduleReconnect();
        });
    }

    function teardownShared() {
        try { if (sharedClient) { sharedClient.disconnect(); } } catch (e) {}
        sharedClient = null;
        sharedReady = false;
        if (presenceTimer) { clearInterval(presenceTimer); presenceTimer = null; }
    }

    function scheduleReconnect() {
        if (reconnectTimer) { return; }
        reconnectTimer = setTimeout(function () {
            reconnectTimer = null;
            if (active || inboundBusy || starting) { scheduleReconnect(); return; }
            teardownShared();
            connectShared();
        }, 20000);
    }

    function heartbeat() {
        if (!sharedReady) { return; }
        api('POST', inboundCfg.presenceUrl).then(function (r) {
            if (r.status === 503 || r.status === 403 || r.status === 404) { teardownShared(); } // no longer available: stop registering
        }).catch(function () { /* the next beat retries */ });
    }

    function hangUp() {
        if (finished || !active) { return; }
        // Cancel on an incoming call that has not been claimed yet (e.g. the microphone prompt is still open): this is a
        // decline, not a hangup. Bumping the attempt invalidates the pending microphone operation.
        if (direction === 'inbound' && pendingSdk && !sdkCall) {
            attempt++;
            declineInbound();
            return;
        }
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
    $('softphone-answer').addEventListener('click', answerInbound);
    $('softphone-decline').addEventListener('click', declineInbound);
    // "Call again" reuses the exact same start sequence (and its server checks) as the contact's call button.
    $('softphone-again').addEventListener('click', start);

    // Leaving the page must not leave a call (or a charge) running on the server.
    window.addEventListener('pagehide', function () {
        // A ringing (not yet answered) inbound popup must not hang the call up just because this page is being left.
        if (active && !finished && callUid && root.getAttribute('data-state') !== 'incoming') {
            api('POST', cfg.hangupUrl.replace('__CALL__', encodeURIComponent(callUid)), null, true);
        }
    });

    // Leaving a page ends the browser's audio: warn before a live INBOUND call is dropped by navigating away.
    window.addEventListener('beforeunload', function (e) {
        if (direction === 'inbound' && active && !finished && root.getAttribute('data-state') === 'connected') {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    if (inboundCfg) { connectShared(); }
})();
