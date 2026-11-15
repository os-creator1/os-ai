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
    // Keypad (DTMF), hold and note are implemented below. Record and Transfer have no safe backend: their buttons are
    // rendered disabled (with the reason in their title) instead of being hidden or pretending to work.
    var capabilities = { keypad: true, hold: true, record: false, transfer: false, note: true };
    root.querySelectorAll('[data-capability]').forEach(function (el) {
        el.hidden = !capabilities[el.getAttribute('data-capability')] && !el.disabled;
    });
    var keypadBtn = $('softphone-keypad');
    var holdBtn = $('softphone-hold');
    var holdLabelEl = $('softphone-hold-label');
    var holdBanner = $('softphone-hold-banner');
    var noteBtn = $('softphone-notebtn');
    var noteView = $('softphone-note-view');
    var noteText = $('softphone-note-text');
    var keypadView = $('softphone-keypad-view');
    var digitsEl = $('softphone-digits');
    var fromEl = $('softphone-from');
    var hintEl = $('softphone-hint');
    var wrapEl = $('softphone-wrapup');
    var wrapNote = $('softphone-wrap-note');
    var saveMsg = $('softphone-save-msg');
    var doneLabel = $('softphone-done-label');
    var chooserEl = $('softphone-chooser');
    var chooseRow = $('softphone-choose-row');
    var chosenFrom = null;
    var audioBtn = $('softphone-audio');
    var audioList = $('softphone-audio-list');
    var audioOpen = false;
    var held = false;
    var wasAnswered = false;
    var keypadOpen = false;
    var noteOpen = false;
    var digits = '';
    var disposition = null;
    var fromNumber = '';
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
        number_not_available: 'That calling number is not available any more. Choose another number.',
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
        var incoming = state === 'incoming';
        var live = isLive(state);
        var inCall = state === 'connected';
        if (!live) { minimized = false; held = false; keypadOpen = false; noteOpen = false; }
        if (inCall && held) { text = 'On hold'; }
        root.setAttribute('data-state', state);
        root.setAttribute('data-held', inCall && held ? '1' : '0');
        statusEl.textContent = text;
        detailEl.textContent = detail || '';
        detailEl.hidden = !detail || keypadOpen;
        hangRow.hidden = !live || keypadOpen;
        hangLabelEl.textContent = state === 'connected' ? 'End call' : 'Cancel';
        var choosing = state === 'choose';
        if (chooserEl) { chooserEl.hidden = !choosing; }
        if (chooseRow) { chooseRow.hidden = !choosing; }
        incomingRow.hidden = !incoming;
        controlsEl.hidden = !live || keypadOpen;
        muteBtn.disabled = !inCall;
        pillMute.disabled = !inCall;
        if (keypadBtn) { keypadBtn.disabled = !inCall; keypadBtn.setAttribute('aria-pressed', keypadOpen ? 'true' : 'false'); }
        if (holdBtn) {
            holdBtn.disabled = !inCall;
            holdBtn.classList.toggle('on', held);
            holdBtn.setAttribute('aria-pressed', held ? 'true' : 'false');
            if (holdLabelEl) { holdLabelEl.textContent = held ? 'Resume' : 'Hold'; }
            var pause = holdBtn.querySelector('[data-role="hold-icon-pause"]');
            var play = holdBtn.querySelector('[data-role="hold-icon-play"]');
            if (pause) { pause.hidden = held; }
            if (play) { play.hidden = !held; }
        }
        // Before the call connects only Mute and Keypad exist in the approved design; the rest appear once there is a live call.
        // While taking a note the design shows four controls (Mute, Keypad, Hold, Note), so Record/Transfer step aside.
        [holdBtn, noteBtn].forEach(function (b) { if (b) { b.hidden = !inCall; } });
        [$('softphone-record'), $('softphone-transfer')].forEach(function (b) { if (b) { b.hidden = !inCall || noteOpen; } });
        controlsEl.classList.toggle('is-four', noteOpen);
        // The keypad view replaces the identity block (design state 4).
        var whoEl = root.querySelector('.mg-sp-who');
        if (whoEl) { whoEl.hidden = keypadOpen && live; }
        // Audio output: real browser capability (setSinkId), offered while the call is being placed (design state 2).
        if (audioBtn) {
            var placing = state === 'connecting' || state === 'ringing';
            audioBtn.hidden = !placing;
            audioBtn.disabled = !audioSupported();
            audioBtn.title = audioSupported() ? 'Choose where you hear the call' : 'This browser cannot choose an audio output.';
            audioBtn.classList.toggle('on', audioOpen && placing);
            if (!placing) { audioOpen = false; }
            if (audioList) { audioList.hidden = !(audioOpen && placing); }
        }
        if (noteBtn) { noteBtn.disabled = !live; noteBtn.classList.toggle('on', noteOpen); noteBtn.setAttribute('aria-pressed', noteOpen ? 'true' : 'false'); }
        if (keypadView) { keypadView.hidden = !(keypadOpen && live); }
        if (noteView) { noteView.hidden = !(noteOpen && live); }
        if (hintEl) { hintEl.hidden = !inCall || keypadOpen || noteOpen || held; }
        if (holdBanner) {
            holdBanner.hidden = !(held && inCall);
            holdBanner.textContent = held ? 'The call is on hold. Call time: ' + (timerEl ? timerEl.textContent : '') : '';
        }
        if (fromEl) { fromEl.hidden = !fromNumber || incoming || keypadOpen; fromEl.textContent = fromNumber ? 'Calling from ' + fromNumber : ''; }
        root.setAttribute('data-has-from', fromNumber && !incoming && !keypadOpen ? '1' : '0');
        minBtn.hidden = !live;
        closeBtn.hidden = live || incoming;
        doneRow.hidden = live || incoming || choosing;
        againBtn.hidden = direction === 'inbound' || !(cfg.contactUid || cfg.conversationUid);
        var ended = state === 'ended' || state === 'failed';
        summaryEl.hidden = !ended || (state === 'ended' && !!callUid && !!cfg.wrapupUrl && !!wrapEl && wasAnswered);
        if (choosing) { timerEl.hidden = true; }
        // The wrap-up (How did it go? + note) is offered only for a call that was really answered and that the server recorded.
        var wrapOk = state === 'ended' && !!callUid && !!cfg.wrapupUrl && !!wrapEl && wasAnswered;
        if (wrapEl) { wrapEl.hidden = !wrapOk; }
        if (doneLabel) { doneLabel.textContent = wrapOk ? 'Save' : 'Close'; }
        timerEl.hidden = state !== 'connected' && state !== 'ended';
        var sub = subEl.getAttribute('data-sub') || '';
        subEl.textContent = (state === 'ended' || state === 'failed') ? (wrapOk ? 'Saved to this contact' : dirLabel() + ' call') : (sub ? sub + ' \u00b7 ' : '') + dirLabel();
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
        wasAnswered = connected;
        var missed = direction === 'inbound' ? (call.outcome === 'declined' ? 'Declined' : 'Missed') : 'Not answered';
        showSummary(connected ? 'connected' : 'unanswered', connected ? 'Connected' : missed, connected ? seconds || 0 : 0);
        setState('ended', connected || direction !== 'inbound' ? 'Call ended' : (missed + ' call'), '');
        showTime(fmt(connected ? seconds || 0 : 0));
        timerEl.hidden = !connected;
        refreshHistory();
    }

    function refreshHistory() {
        // In the calling shell the contact page is in the app frame; otherwise it is this document.
        var frame = document.getElementById('mg-app-frame');
        var pageDoc = document;
        try { if (frame && frame.contentDocument) { pageDoc = frame.contentDocument; } } catch (e) { pageDoc = document; }
        var holder = pageDoc.querySelector('[data-role="contact-call-history"]');
        if (holder && holder.getAttribute('data-reload') === '1') {
            if (reloadTimer) { clearTimeout(reloadTimer); }
            // Never reload the page out from under a call the user has started in the meantime.
            reloadTimer = setTimeout(function () {
                if (active || starting) { return; }
                try { (frame && frame.contentWindow ? frame.contentWindow : window).location.reload(); } catch (e) { window.location.reload(); }
            }, 1200);
        }
    }

    function applyServerState(call) {
        if (finished) { return; }
        switch (call.ui_state) {
            case 'connecting': setState('connecting', 'Connecting…', 'Setting up your call.'); break;
            case 'ringing':
                if (call.from_number) { fromNumber = call.from_number; }
                if (direction === 'inbound') { setState('connecting', 'Connecting…', 'Connecting your call.'); } else { setState('ringing', 'Calling…', ''); }
                break;
            case 'connected': if (call.from_number) { fromNumber = call.from_number; } setState('connected', 'On call', ''); startTimer(); break;
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

    function begin() {
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
        resetCallUi();
        minimized = false;
        direction = 'outbound';
        setIdentity(cfg.contactLabel || '', cfg.contactPhone || '', cfg.contactInitials || '#');
        show();
        setState('connecting', 'Allow microphone access', 'Your browser will ask to use the microphone.');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.TelnyxWebRTC) {
            fail('This browser cannot make calls. Use a current version of Chrome, Edge, Firefox or Safari.');
            starting = false; // nothing is on the network: a later attempt (another contact) must not be blocked as "still starting"
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
            setState('connecting', 'Calling…', '');
            // A linked contact is called by its uid; an unlinked conversation by the conversation's own uid (the server resolves the number).
            var target = cfg.contactUid ? { contact_uid: cfg.contactUid } : { conversation_uid: cfg.conversationUid };
            var placeBody = Object.assign(target, { idempotency_key: uuid(), channel: 'browser' });
            if (chosenFrom) { placeBody.from = chosenFrom; }
            return api('POST', cfg.placeUrl, placeBody);
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

    // Entry point for every outbound call. With ONE eligible number (or no selector configured) it dials at once; with
    // several, the person picks which of THIS business's voice-ready numbers to call from first.
    var chooseGen = 0;
    function start() {
        if (active) { show(); return; }
        if (starting) { begin(); return; }
        if (!cfg.numbersUrl) { chosenFrom = null; begin(); return; }
        var gen = ++chooseGen;
        api('GET', cfg.numbersUrl).then(function (r) {
            return r.ok && r.json && Array.isArray(r.json.data) ? r.json.data : null;
        }, function () { return null; }).then(function (list) {
            if (gen !== chooseGen || active || starting) { return; }
            // The list could not be read: the server's own default (the primary number) applies; nothing else is guessed.
            if (list === null || list.length < 2) { chosenFrom = null; begin(); return; }
            showChooser(list);
        });
    }

    function showChooser(list) {
        var key = 'mg-call-from:' + cfg.numbersUrl, remembered = null;
        try { remembered = window.localStorage.getItem(key); } catch (e) {}
        var selected = list.some(function (n) { return n.id === remembered; }) ? remembered : list[0].id;
        chooserEl.textContent = '';
        list.forEach(function (n) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'mg-sp-nr'; b.setAttribute('role', 'radio'); b.setAttribute('data-from', n.id);
            var rd = document.createElement('i'); rd.className = 'rd';
            var num = document.createElement('b'); num.textContent = n.number;
            b.appendChild(rd); b.appendChild(num);
            if (n.primary) { var p = document.createElement('span'); p.className = 'p'; p.textContent = 'Primary'; b.appendChild(p); }
            b.addEventListener('click', function () { selected = n.id; paint(); });
            chooserEl.appendChild(b);
        });
        function paint() {
            chooserEl.querySelectorAll('[data-from]').forEach(function (b) { b.setAttribute('aria-checked', b.getAttribute('data-from') === selected ? 'true' : 'false'); });
        }
        paint();
        direction = 'outbound';
        setIdentity(cfg.contactLabel || '', cfg.contactPhone || '', cfg.contactInitials || '#');
        show();
        setState('choose', 'Call from', '');
        var go = $('softphone-choose-go');
        go.onclick = function () { chosenFrom = selected; try { window.localStorage.setItem(key, selected); } catch (e) {} begin(); };
        $('softphone-choose-cancel').onclick = function () { chooseGen++; closePopup(); };
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

    function resetCallUi() {
        held = false; keypadOpen = false; noteOpen = false; wasAnswered = false; fromNumber = '';
        digits = ''; disposition = null;
        if (digitsEl) { digitsEl.textContent = ''; }
        if (noteText) { noteText.value = ''; }
        if (wrapNote) { wrapNote.value = ''; }
        if (saveMsg) { saveMsg.hidden = true; saveMsg.textContent = ''; }
        root.querySelectorAll('[data-disposition]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
    }

    function audioSupported() {
        return !!(audioEl && typeof audioEl.setSinkId === 'function' && navigator.mediaDevices && typeof navigator.mediaDevices.enumerateDevices === 'function');
    }

    function toggleAudio() {
        if (!audioSupported()) { return; }
        if (audioOpen) { audioOpen = false; setState(root.getAttribute('data-state'), statusEl.textContent, detailEl.hidden ? '' : detailEl.textContent); return; }
        navigator.mediaDevices.enumerateDevices().then(function (devices) {
            var outs = devices.filter(function (d) { return d.kind === 'audiooutput'; });
            audioList.textContent = '';
            var key = 'mg-audio-out', saved = null;
            try { saved = window.localStorage.getItem(key); } catch (e) {}
            outs.forEach(function (d, i) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'mg-sp-nr'; b.setAttribute('role', 'radio');
                b.setAttribute('aria-checked', (saved ? saved === d.deviceId : d.deviceId === 'default' || i === 0) ? 'true' : 'false');
                var rd = document.createElement('i'); rd.className = 'rd';
                var nm = document.createElement('b'); nm.textContent = d.label || ('Speaker ' + (i + 1));
                b.appendChild(rd); b.appendChild(nm);
                b.addEventListener('click', function () {
                    audioEl.setSinkId(d.deviceId).then(function () {
                        try { window.localStorage.setItem(key, d.deviceId); } catch (e) {}
                        audioList.querySelectorAll('[role=radio]').forEach(function (o) { o.setAttribute('aria-checked', o === b ? 'true' : 'false'); });
                        audioOpen = false;
                        setState(root.getAttribute('data-state'), statusEl.textContent, '');
                    }).catch(function () { detailEl.textContent = 'That audio output could not be selected.'; detailEl.hidden = false; });
                });
                audioList.appendChild(b);
            });
            if (!outs.length) { var none = document.createElement('p'); none.className = 'mg-sp-hint'; none.textContent = 'No other audio outputs were found.'; audioList.appendChild(none); }
            audioOpen = true;
            setState(root.getAttribute('data-state'), statusEl.textContent, detailEl.hidden ? '' : detailEl.textContent);
        }).catch(function () { /* devices unreadable: the control simply does nothing */ });
    }

    function toggleKeypad(open) {
        if (root.getAttribute('data-state') !== 'connected') { return; }
        keypadOpen = open;
        var nm = $('softphone-keypad-name');
        if (nm) { nm.textContent = peerName; }
        setState('connected', 'On call', detailEl.textContent);
    }

    function sendDigit(d) {
        if (!sdkCall || root.getAttribute('data-state') !== 'connected') { return; }
        try { sdkCall.dtmf(d); } catch (e) { return; }
        digits += d;
        if (digitsEl) { digitsEl.textContent = digits.split('').join(' '); }
    }

    function toggleHold() {
        if (!sdkCall || root.getAttribute('data-state') !== 'connected') { return; }
        var wanted = !held;
        holdBtn.disabled = true;
        var op;
        try { op = wanted ? sdkCall.hold() : sdkCall.unhold(); } catch (e) { op = Promise.reject(e); }
        Promise.resolve(op).then(function () {
            held = wanted;
        }).catch(function () {
            // The SDK refused: say so, never pretend the call is held.
            detailEl.textContent = wanted ? 'The call could not be put on hold.' : 'The call could not be resumed.';
            detailEl.hidden = false;
        }).then(function () { if (root.getAttribute('data-state') === 'connected') { setState('connected', 'On call', detailEl.hidden ? '' : detailEl.textContent); } });
    }

    function toggleNote() {
        noteOpen = !noteOpen;
        setState(root.getAttribute('data-state'), statusEl.textContent, detailEl.hidden ? '' : detailEl.textContent);
        if (noteOpen && noteText) { noteText.focus(); }
    }

    // Save the post-call wrap-up (disposition + note). Never claims success unless the server confirmed it.
    function saveWrapup() {
        if (!wrapEl || wrapEl.hidden || !callUid) { closePopup(); return; }
        var text = ((wrapNote && wrapNote.value) || (noteText && noteText.value) || '').trim();
        if (wrapNote && !wrapNote.value && noteText && noteText.value) { wrapNote.value = noteText.value; }
        var body = { note: ((wrapNote && wrapNote.value) || '').trim() || null, disposition: disposition };
        if (!body.note && !body.disposition) { closePopup(); return; }
        var button = $('softphone-done');
        button.disabled = true;
        api('POST', cfg.wrapupUrl.replace('__CALL__', encodeURIComponent(callUid)), body).then(function (r) {
            if (r.ok) { closePopup(); return; }
            saveMsg.textContent = (r.json && r.json.error && r.json.error.message) || 'The note could not be saved. Try again.';
            saveMsg.hidden = false;
        }).catch(function () {
            saveMsg.textContent = 'The note could not be saved. Check your connection and try again.';
            saveMsg.hidden = false;
        }).then(function () { button.disabled = false; });
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
    $('softphone-done').addEventListener('click', saveWrapup);
    if (keypadBtn) { keypadBtn.addEventListener('click', function () { toggleKeypad(true); }); }
    var kpHide = $('softphone-keypad-hide');
    if (kpHide) { kpHide.addEventListener('click', function () { toggleKeypad(false); }); }
    var kpHang = $('softphone-keypad-hangup');
    if (kpHang) { kpHang.addEventListener('click', hangUp); }
    root.querySelectorAll('[data-digit]').forEach(function (b) { b.addEventListener('click', function () { sendDigit(b.getAttribute('data-digit')); }); });
    if (holdBtn) { holdBtn.addEventListener('click', toggleHold); }
    if (audioBtn) { audioBtn.addEventListener('click', toggleAudio); }
    if (noteBtn) { noteBtn.addEventListener('click', toggleNote); }
    root.querySelectorAll('[data-disposition]').forEach(function (b) {
        b.addEventListener('click', function () {
            var key = b.getAttribute('data-disposition');
            disposition = disposition === key ? null : key;
            root.querySelectorAll('[data-disposition]').forEach(function (o) { o.setAttribute('aria-pressed', o.getAttribute('data-disposition') === disposition ? 'true' : 'false'); });
        });
    });
    // The in-call note carries over into the wrap-up once the call ends.
    if (noteText && wrapNote) { noteText.addEventListener('input', function () { wrapNote.value = noteText.value; }); }
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

    // Pages with many contacts (Conversations) have no single contact baked into the page: they hand the softphone the ONE
    // contact the user chose, at click time. This is the same start() sequence and the same server checks as the profile's
    // Call button; nothing is dialled here. A call that is already live or still starting is never re-targeted, so its popup
    // keeps showing who is really on the line; otherwise the previous contact's identity is fully replaced.
    window.MGSoftphone = {
        callContact: function (contact) {
            var hasUid = !!contact && typeof contact.uid === 'string' && contact.uid !== '';
            var hasConversation = !!contact && typeof contact.conversation === 'string' && contact.conversation !== '';
            if (!hasUid && !hasConversation) { return false; }
            if (!active && !starting) {
                cfg.contactUid = hasUid ? contact.uid : null;
                cfg.conversationUid = hasUid ? null : contact.conversation;
                cfg.contactLabel = String(contact.label || '');
                cfg.contactPhone = String(contact.phone || '');
                cfg.contactInitials = String(contact.initials || '#');
            }
            start();
            return true;
        }
    };

    if (inboundCfg) { connectShared(); }
})();
