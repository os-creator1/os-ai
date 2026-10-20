/*=========================================================================================
    File Name: softphone-core.js
    Description: The browser-calling state machine, with NO DOM and NO direct Telnyx import.
    Everything it touches arrives through `deps`, so the same code runs in the page (with the real
    Telnyx WebRTC SDK adapter, see softphone.js) and in node tests (with a deterministic fake).

    What is authoritative here: only what the SDK reports on the call object (its documented states)
    and what MotionGrove's server answers. Local state is the interface's view, never the call's
    recorded outcome — the outcome is written by the server from verified Telnyx events.

    One instance per page owns: ONE SDK client, ONE set of listeners, at most ONE call.
==========================================================================================*/
(function (root, factory) {
  "use strict";
  if (typeof module === "object" && module.exports) { module.exports = factory(); }
  else { root.MGSoftphoneCore = factory(); }
})(typeof self !== "undefined" ? self : this, function () {
  "use strict";

  var HEARTBEAT_MS = 25000;
  var RECONNECT_DELAYS = [1000, 3000, 8000, 20000];
  var TOKEN_REFRESH_SKEW_S = 120;
  var RECOVERY_WAIT_MS = 15000;
  var STATUS_POLL_MS = 1500;
  var LEG_WAIT_MS = 25000;
  var PROVIDER_WATCH_MS = 3000;
  var PROVIDER_WATCH_MAX = 12;

  function friendlyCause(cause) {
    var c = String(cause || "").toLowerCase();
    if (c.indexOf("busy") !== -1) { return "The number is busy."; }
    if (c.indexOf("no_answer") !== -1 || c.indexOf("timeout") !== -1 || c.indexOf("no answer") !== -1) { return "Nobody answered."; }
    if (c.indexOf("reject") !== -1 || c.indexOf("decline") !== -1) { return "The call was declined."; }
    if (c.indexOf("unallocated") !== -1 || c.indexOf("invalid") !== -1 || c.indexOf("not found") !== -1) { return "That number could not be reached. Check that it is correct."; }
    if (c.indexOf("normal") !== -1) { return null; }
    return "The call could not be completed.";
  }

  function createSoftphone(deps) {
    var state = {
      registration: "off", // off | registering | registered | disconnected | failed | other_tab | unsupported | insecure
      registrationMessage: null,
      call: null
    };
    var listeners = [];
    var client = null;
    var rtcCall = null;
    var session = null;           // { inbound_number_uid } from the server
    var registeredNumberUid = null;
    var registeredScope = null;
    var heartbeatTimer = null;
    var reconnectTimer = null;
    var recoveryTimer = null;
    var refreshTimer = null;
    var reconnectAttempt = 0;
    var starting = false;
    var pendingLeg = null;
    var statusTimer = null;
    var disposed = false;
    var weEnded = false;

    function emit() { listeners.slice().forEach(function (fn) { try { fn(state); } catch (e) { /* a view must never break calling */ } }); }
    function set(patch) { Object.keys(patch).forEach(function (k) { state[k] = patch[k]; }); emit(); }
    function setCall(patch) { if (state.call) { Object.keys(patch).forEach(function (k) { state.call[k] = patch[k]; }); emit(); } }
    function inCall() { return state.call !== null && state.call.phase !== "ended"; }

    /* ------------------------------------------------------------ registration */

    function supportProblem() {
      if (!deps.supported) { return { code: "unsupported", message: "This browser can't place calls. Use a current version of Chrome, Edge, Firefox or Safari." }; }
      if (!deps.isSecure) { return { code: "insecure", message: "Calling needs a secure (HTTPS) connection." }; }
      return null;
    }

    function init() {
      var problem = supportProblem();
      if (problem) { set({ registration: problem.code, registrationMessage: problem.message }); return Promise.resolve(); }
      return deps.api.get(deps.urls.session).then(function (res) {
        if (!res.ok) { return; }
        session = res.json || {};
        if (session.inbound_number_uid) { return register(session.inbound_number_uid, session.inbound_scope || null); }
      });
    }

    // Registers the (single) SDK client for the account scope of this number. Safe to call repeatedly.
    function register(numberUid, scope) {
      if (disposed) { return Promise.resolve(); }
      var problem = supportProblem();
      if (problem) { set({ registration: problem.code, registrationMessage: problem.message }); return Promise.resolve(); }
      if (client && state.registration === "registered" && (registeredNumberUid === numberUid || (scope && registeredScope === scope))) { return Promise.resolve(); }
      // Never rebuild the client under a live call — except to place the call that is being started now.
      if (inCall() && !starting) { return Promise.resolve(); }
      if (!deps.leader.tryAcquire()) {
        set({ registration: "other_tab", registrationMessage: "Calling is active in another MotionGrove tab. Use calling here instead?" });
        return Promise.resolve();
      }
      teardownClient();
      set({ registration: "registering", registrationMessage: null });
      return Promise.resolve(deps.prepare ? deps.prepare() : null).then(function () { return fetchToken(numberUid); }).then(function (tok) {
        if (disposed) { return; }
        if (!tok.ok) { return registrationFailed(tok); }
        registeredNumberUid = numberUid;
        registeredScope = scope || null;
        var handlers = clientHandlers();
        client = deps.createClient(tok.token, handlers);
        handlers.bind(client);
        scheduleRefresh(tok.expires_at);
        client.connect();
      });
    }

    function fetchToken(numberUid) {
      return deps.api.post(deps.urls.token, { number_uid: numberUid }).then(function (res) {
        if (res.ok && res.json && res.json.token) { return { ok: true, token: res.json.token, expires_at: res.json.expires_at || null }; }
        return { ok: false, code: (res.json && res.json.code) || "token_failed", message: (res.json && res.json.message) || "Calling could not be started. Please try again." };
      }, function () { return { ok: false, code: "network", message: "You appear to be offline. Calling will resume when you reconnect." }; });
    }

    function registrationFailed(info) {
      var retriable = info.code === "network" || info.code === "provider_unavailable" || info.code === "token_failed";
      set({ registration: retriable ? "disconnected" : "failed", registrationMessage: info.message });
      if (retriable) { scheduleReconnect(); }
    }

    function scheduleReconnect() {
      if (disposed || reconnectTimer || inCall() || !registeredNumberUidOrSession()) { return; }
      var delay = RECONNECT_DELAYS[Math.min(reconnectAttempt, RECONNECT_DELAYS.length - 1)];
      reconnectAttempt += 1;
      reconnectTimer = deps.setTimeout(function () {
        reconnectTimer = null;
        register(registeredNumberUid || (session && session.inbound_number_uid), registeredScope);
      }, delay);
    }

    function registeredNumberUidOrSession() { return registeredNumberUid || (session && session.inbound_number_uid); }

    function scheduleRefresh(expiresAt) {
      deps.clearTimeout(refreshTimer);
      refreshTimer = null;
      if (!expiresAt) { return; }
      var ms = (expiresAt - TOKEN_REFRESH_SKEW_S) * 1000 - deps.now();
      if (ms < 5000) { ms = 5000; }
      // Re-authenticate before the token lapses, but never in the middle of a call.
      refreshTimer = deps.setTimeout(function () {
        if (inCall()) { scheduleRefresh(deps.now() / 1000 + TOKEN_REFRESH_SKEW_S + 30); return; }
        register(registeredNumberUid, registeredScope);
      }, ms);
    }

    function clientHandlers() {
      var mine = null;
      return {
        bind: function (c) { mine = c; },
        ready: function () {
          if (c() === false) { return; }
          reconnectAttempt = 0;
          deps.clearTimeout(recoveryTimer);
          recoveryTimer = null;
          set({ registration: "registered", registrationMessage: null });
          startHeartbeat();
        },
        error: function (err) {
          if (c() === false) { return; }
          var authFailure = err && /auth|login|token|credential|unauthor|expired/i.test(JSON.stringify(err && (err.error || err.message || err.code) || err));
          if (inCall()) { setCall({ notice: "Connection problem. Trying to recover…" }); return; }
          if (authFailure) {
            // Expired or rejected token: only a fresh server-minted token helps; rebuild the client.
            set({ registration: "disconnected", registrationMessage: "Your calling session expired. Reconnecting…" });
            stopHeartbeat(); teardownClient(); scheduleReconnect();
            return;
          }
          lostConnection();
        },
        socketClose: function () {
          if (c() === false) { return; }
          if (inCall()) { setCall({ notice: "Connection lost. Trying to recover the call…" }); return; }
          lostConnection();
        },
        callUpdate: function (call) { if (c() !== false) { onCallUpdate(call); } }
      };
      // Events from a client that has already been replaced are ignored (no duplicate listeners effect).
      function c() { return mine === null || mine === client; }
    }

    // The SDK reconnects its own socket. Give it a moment; if it has not become ready again, start over
    // with a fresh token rather than leaving the user believing inbound calls can still reach them.
    function lostConnection() {
      stopHeartbeat();
      set({ registration: "disconnected", registrationMessage: "Calling lost its connection. Reconnecting…" });
      deps.clearTimeout(recoveryTimer);
      recoveryTimer = deps.setTimeout(function () {
        recoveryTimer = null;
        if (state.registration === "disconnected" && !inCall()) { teardownClient(); scheduleReconnect(); }
      }, RECOVERY_WAIT_MS);
    }

    function startHeartbeat() {
      stopHeartbeat();
      var beat = function () {
        if (!registeredNumberUid) { return; }
        deps.leader.renew();
        deps.api.post(deps.urls.heartbeat, { number_uid: registeredNumberUid });
      };
      beat();
      heartbeatTimer = deps.setInterval(beat, HEARTBEAT_MS);
    }

    function stopHeartbeat() { if (heartbeatTimer) { deps.clearInterval(heartbeatTimer); heartbeatTimer = null; } }

    function teardownClient() {
      deps.clearTimeout(refreshTimer);
      refreshTimer = null;
      if (client) { var old = client; client = null; try { old.disconnect(); } catch (e) { /* already gone */ } }
    }

    /* ---------------------------------------------------------------- outgoing */

    function loadOptions(target) {
      var qs = target.contactUid ? "contact_uid=" + encodeURIComponent(target.contactUid) : "conversation_uid=" + encodeURIComponent(target.conversationUid);
      return deps.api.get(deps.urls.options + "?" + qs).then(function (res) {
        if (!res.ok) { return { callable: false, message: (res.json && res.json.message) || "Calling is not available here.", numbers: [] }; }
        return res.json;
      }, function () { return { callable: false, message: "You appear to be offline.", numbers: [] }; });
    }

    // MotionGrove places the call (server-mediated): the browser only registers, asks the server to dial, and
    // then ANSWERS the one leg the server rings for that exact request. It never dials by itself.
    function startCall(req) {
      var problem = supportProblem();
      if (problem) { return Promise.resolve(fail(problem.message, problem.code)); }
      if (starting || inCall()) { return Promise.resolve({ ok: false, code: "call_in_progress", message: "You already have a call in progress." }); }
      starting = true;
      var key = deps.uuid();

      show({ phase: "dialing", direction: "outbound", number: req.toNumber, name: req.name, from: req.fromNumber, callUid: null, serverLeg: true });

      return deps.checkMicrophone().then(function (mic) {
        if (!mic.ok) { return fail(mic.message, "mic_" + mic.code); }
        // The browser identity must be registered BEFORE the server rings it.
        return ensureClientFor(req.numberUid, req.scope).then(function (ready) {
          if (!ready.ok) { return fail(ready.message, ready.code); }
          return deps.api.post(deps.urls.calls, { number_uid: req.numberUid, contact_uid: req.contactUid || null, conversation_uid: req.conversationUid || null, idempotency_key: key })
            .then(function (res) {
              if (!res.ok) { return fail((res.json && res.json.message) || "The call could not be started.", (res.json && res.json.code) || "start_failed"); }
              var info = res.json.call;
              pendingLeg = { to: digits(info.to), expiresAt: deps.now() + LEG_WAIT_MS };
              setCall({ callUid: info.uid, number: info.to, from: info.from, name: info.name || null });
              startStatusPolling();
              return { ok: true };
            }, function () { return fail("You appear to be offline. Check your connection and try again.", "network"); });
        });
      }).then(function (r) { starting = false; return r; }, function (e) { starting = false; throw e; });
    }

    function digits(n) { return String(n || "").replace(/\D/g, ""); }

    // The server is the authority on when the CUSTOMER answered; the SDK's "active" only means our own leg's media is up.
    function startStatusPolling() {
      stopStatusPolling();
      statusTimer = deps.setInterval(function () {
        var c = state.call;
        if (!c || !c.callUid || c.phase === "ended" || c.direction !== "outbound") { stopStatusPolling(); return; }
        if (pendingLeg && deps.now() > pendingLeg.expiresAt && !rtcCall) {
          pendingLeg = null;
          fail("Your browser did not pick up the call in time. Check your microphone and connection, then try again.", "leg_timeout");
          requestCancel(c.callUid);
          stopStatusPolling();
          return;
        }
        deps.api.get(deps.urls.callStatus(c.callUid)).then(function (res) {
          var cur = state.call;
          if (!res.ok || !res.json || !cur || cur.callUid !== c.callUid || cur.phase === "ended") { return; }
          if (res.json.call_status === "answered" && cur.phase !== "active") {
            setCall({ phase: "active", answeredAt: deps.now() - (res.json.answered_elapsed_ms || 0), notice: null });
          } else if (res.json.final) {
            applyServerOutcome(res.json);
          }
        }, function () { /* a missed poll is harmless; the next one decides */ });
      }, STATUS_POLL_MS);
    }

    // Canceling is the user's decision and is final in the panel at once; whether the PHONE PROVIDER has confirmed the
    // call stopped is a separate, honestly reported fact: pending (waiting) -> confirmed, or attention (could not confirm).
    function requestCancel(uid) {
      applyProviderHangup(uid, "pending", null);
      return Promise.resolve(deps.api.post(deps.urls.cancel(uid), {})).then(function (res) {
        var json = res && res.json ? res.json : {};
        applyProviderHangup(uid, res && res.ok && json.provider_hangup ? json.provider_hangup : "pending", json.message || null);
        watchProviderHangup(uid, 0);
      }, function () { watchProviderHangup(uid, 0); });
    }

    function applyProviderHangup(uid, status, message) {
      var c = state.call;
      if (!c || c.callUid !== uid || !status) { return; }
      setCall({ providerHangup: status, providerNote: message || null });
    }

    function watchProviderHangup(uid, attempt) {
      var c = state.call;
      if (!c || c.callUid !== uid || c.providerHangup !== "pending" || attempt >= PROVIDER_WATCH_MAX) { return; }
      deps.setTimeout(function () {
        Promise.resolve(deps.api.get(deps.urls.callStatus(uid))).then(function (res) {
          if (res && res.ok && res.json && res.json.provider_hangup) { applyProviderHangup(uid, res.json.provider_hangup, res.json.message); }
          watchProviderHangup(uid, attempt + 1);
        }, function () { watchProviderHangup(uid, attempt + 1); });
      }, PROVIDER_WATCH_MS);
    }

    function stopStatusPolling() { if (statusTimer) { deps.clearInterval(statusTimer); statusTimer = null; } }

    function applyServerOutcome(json) {
      var c = state.call;
      if (!c || c.phase === "ended") { return; }
      var map = { completed: "Call ended.", busy: "The number is busy.", no_answer: "Nobody answered.", declined: "The call was declined.", canceled: "Call canceled.", failed: "The call could not be completed.", missed: "The call ended." };
      var failed = json.call_status === "failed" || json.call_status === "busy" || json.call_status === "no_answer" || json.call_status === "declined";
      stopStatusPolling();
      if (rtcCall) { weEnded = true; try { rtcCall.hangup(); } catch (e) { /* already gone */ } }
      setCall({ phase: "ended", endReason: { code: json.call_status, message: map[json.call_status] || "The call ended.", failed: failed }, durationSeconds: c.answeredAt ? Math.max(0, Math.round((deps.now() - c.answeredAt) / 1000)) : 0, notice: null, providerHangup: json.provider_hangup || null, providerNote: json.message || null });
      rtcCall = null;
    }

    // A call needs a connected client for the chosen number's account. Waits (bounded) for ready.
    function ensureClientFor(numberUid, scope) {
      if (client && state.registration === "registered" && (registeredNumberUid === numberUid || (scope && registeredScope === scope))) { return Promise.resolve({ ok: true }); }
      return register(numberUid, scope).then(function () {
        if (state.registration === "other_tab") { return { ok: false, code: "other_tab", message: "Calling is active in another MotionGrove tab. Use calling in this tab first." }; }
        if (state.registration === "failed" || state.registration === "unsupported" || state.registration === "insecure") { return { ok: false, code: state.registration, message: state.registrationMessage }; }
        return waitForRegistered();
      });
    }

    function waitForRegistered() {
      return new Promise(function (resolve) {
        var waited = 0;
        (function poll() {
          if (state.registration === "registered") { return resolve({ ok: true }); }
          if (state.registration === "failed" || (state.registration === "disconnected" && waited >= 3000)) {
            return resolve({ ok: false, code: "not_registered", message: state.registrationMessage || "Calling could not connect to Telnyx. Please try again." });
          }
          if (waited >= 10000) { return resolve({ ok: false, code: "timeout", message: "Calling could not connect to Telnyx in time. Please try again." }); }
          waited += 250;
          deps.setTimeout(poll, 250);
        })();
      });
    }

    function show(call) {
      weEnded = false;
      state.call = Object.assign({ muted: false, held: false, answeredAt: null, endReason: null, note: "", notice: null, canHold: true }, call);
      emit();
    }

    function fail(message, code) {
      weEnded = true;
      if (!state.call) { show({ phase: "dialing", direction: "outbound", number: null, name: null, from: null, callUid: null }); }
      setCall({ phase: "ended", endReason: { code: code || "failed", message: message, failed: true }, notice: null });
      rtcCall = null;
      return { ok: false, code: code || "failed", message: message };
    }

    /* ---------------------------------------------------------------- SDK call lifecycle */

    function onCallUpdate(call) {
      if (!call) { return; }
      var s = String(call.state || "").toLowerCase();
      var inbound = String(call.direction || "").toLowerCase() === "inbound";

      if (inbound && call !== rtcCall) {
        var finished = s === "hangup" || s === "destroy" || s === "purge";
        if (finished) { return; }

        // The ONE leg MotionGrove rings for the call this person just asked for: it carries the number they are
        // calling, arrives while the request is pending, and is answered on their behalf (they pressed Call).
        if (pendingLeg && !rtcCall && digits(call.remoteNumber) === pendingLeg.to && deps.now() <= pendingLeg.expiresAt) {
          pendingLeg = null;
          rtcCall = call;
          try { call.answer(); } catch (e) { fail("The call could not be answered in this browser.", "sdk_error"); }
          return;
        }

        // Anything else is a genuine incoming call. One call at a time in V1: a second one is turned away.
        if (rtcCall || inCall() || starting) { try { call.hangup(); } catch (e) { /* ignore */ } return; }
        rtcCall = call;
        show({ phase: "incoming", direction: "inbound", number: call.remoteNumber || null, name: null, from: null, callUid: null });
        lookupCaller(call.remoteNumber);
        return;
      }

      if (call !== rtcCall) { return; }

      if (s === "active") {
        if (state.call.serverLeg) {
          // Our own leg is up; the customer has not necessarily answered. Show ringing until the server says so.
          setCall({ held: false, notice: null, phase: state.call.phase === "active" ? "active" : "ringing" });
        } else if (state.call.phase !== "active") { setCall({ phase: "active", answeredAt: deps.now(), held: false, notice: null }); }
        else { setCall({ held: false, notice: null }); }
      } else if (s === "held") {
        setCall({ held: true });
      } else if (s === "recovering") {
        setCall({ notice: "Reconnecting the call…" });
      } else if (s === "hangup" || s === "destroy" || s === "purge") {
        onEnded(call);
      }
    }

    function onEnded(call) {
      if (!state.call || state.call.phase === "ended") { return; }
      stopStatusPolling();
      var c = state.call;
      var reason;
      if (c.phase === "active") {
        reason = weEnded ? { code: "completed", message: "Call ended." } : { code: "remote_ended", message: "The call ended." };
        if (c.notice && !weEnded) { reason = { code: "connection_lost", message: "The call ended because the connection dropped.", failed: true }; }
      } else if (c.direction === "inbound") {
        reason = weEnded ? { code: "declined", message: "You declined the call." } : { code: "missed", message: "Missed call — the caller hung up." };
      } else {
        var friendly = friendlyCause(call && (call.cause || call.sipReason));
        reason = weEnded ? { code: "canceled", message: "Call canceled." } : { code: "failed", message: friendly || "The call ended before it was answered.", failed: !!friendly };
      }
      setCall({ phase: "ended", endReason: reason, notice: null, durationSeconds: c.answeredAt ? Math.max(0, Math.round((deps.now() - c.answeredAt) / 1000)) : 0 });
      rtcCall = null;
      if (c.callUid) { reportClient("ended"); }
    }

    function lookupCaller(number) {
      if (!number) { return; }
      deps.api.get(deps.urls.lookup + "?number=" + encodeURIComponent(number)).then(function (res) {
        if (res.ok && res.json && state.call && state.call.phase === "incoming") {
          setCall({ name: res.json.name || null, number: res.json.number || number, callUid: res.json.call_uid || null });
        }
      });
    }

    function reportClient(event, detail) {
      var uid = state.call && state.call.callUid;
      if (!uid) { return; }
      deps.api.post(deps.urls.clientEvent(uid), { event: event, detail: detail || null });
    }

    /* ---------------------------------------------------------------- controls */

    function answer() {
      if (!rtcCall || !state.call || state.call.phase !== "incoming") { return Promise.resolve(); }
      return deps.checkMicrophone().then(function (mic) {
        if (!mic.ok) { setCall({ notice: mic.message }); return; }
        setCall({ phase: "dialing", notice: null });
        try { rtcCall.answer(); } catch (e) { setCall({ phase: "ended", endReason: { code: "sdk_error", message: "The call could not be answered in this browser.", failed: true } }); rtcCall = null; }
      });
    }

    function decline() {
      if (!rtcCall) { return; }
      weEnded = true;
      try { rtcCall.hangup(); } catch (e) { /* the end is reported by the SDK event; fall back below */ }
      // If the SDK does not confirm quickly, show the truth we know: we ended it.
      deps.setTimeout(function () { if (state.call && state.call.phase !== "ended" && weEnded && rtcCall) { onEnded({}); } }, 3000);
    }

    function hangup() {
      var c = state.call;
      if (!rtcCall && c && c.serverLeg && c.callUid && c.phase !== "ended") {
        // Cancel before our own leg is up: the server hangs up its dialled leg.
        weEnded = true;
        pendingLeg = null;
        stopStatusPolling();
        setCall({ phase: "ended", endReason: { code: "canceled", message: "Call canceled." }, durationSeconds: 0 });
        requestCancel(c.callUid);
        return;
      }
      decline();
    }

    function toggleMute() {
      if (!rtcCall || !inCall()) { return; }
      try { state.call.muted ? rtcCall.unmuteAudio() : rtcCall.muteAudio(); setCall({ muted: !state.call.muted }); } catch (e) { setCall({ notice: "Mute is not available right now." }); }
    }

    function toggleHold() {
      if (!rtcCall || !state.call || state.call.phase !== "active") { return; }
      var p = state.call.held ? rtcCall.unhold() : rtcCall.hold();
      Promise.resolve(p).then(function () { /* the SDK's own held/active update sets the state */ }, function () { setCall({ notice: "Hold is not available right now." }); });
    }

    function saveNote(text) {
      var uid = state.call && state.call.callUid;
      if (!uid) { return Promise.resolve({ ok: false }); }
      return deps.api.post(deps.urls.note(uid), { note: text }).then(function (res) {
        if (res.ok) { setCall({ note: text, noteSaved: true }); }
        return res;
      });
    }

    function dismiss() { if (state.call && state.call.phase === "ended") { state.call = null; emit(); } }

    function takeOver() {
      deps.leader.takeOver();
      return register(registeredNumberUidOrSession(), registeredScope);
    }

    // Leader moved to another tab: stop being reachable here, honestly, unless a call is live here.
    function onLeaderLost() {
      if (inCall()) { return; }
      stopHeartbeat();
      teardownClient();
      set({ registration: "other_tab", registrationMessage: "Calling is active in another MotionGrove tab." });
    }

    // Logout, workspace switch, page unload: leave nothing registered or ringing behind.
    function shutdown() {
      disposed = true;
      stopHeartbeat();
      stopStatusPolling();
      deps.clearTimeout(reconnectTimer);
      deps.clearTimeout(recoveryTimer);
      reconnectTimer = null;
      recoveryTimer = null;
      if (rtcCall) { weEnded = true; try { rtcCall.hangup(); } catch (e) { /* ignore */ } }
      teardownClient();
      rtcCall = null;
      state.call = null; // the page is going away: nothing left to protect with a "leave this page?" prompt
      deps.leader.release();
      deps.beaconOffline();
      state.registration = "off";
    }

    return {
      getState: function () { return state; },
      subscribe: function (fn) { listeners.push(fn); return function () { listeners = listeners.filter(function (l) { return l !== fn; }); }; },
      init: init, loadOptions: loadOptions, startCall: startCall, answer: answer, decline: decline, hangup: hangup,
      toggleMute: toggleMute, toggleHold: toggleHold, saveNote: saveNote, dismiss: dismiss, takeOver: takeOver,
      onLeaderLost: onLeaderLost, shutdown: shutdown, inCall: inCall,
      _clientHandlersForTest: function () { return client; }
    };
  }

  return { createSoftphone: createSoftphone, friendlyCause: friendlyCause };
});
