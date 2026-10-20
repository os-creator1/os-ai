/*=========================================================================================
    File Name: softphone.js
    Description: MotionGrove browser calling — the page side. Builds the one floating calling panel,
    wires every [data-mg-call] button to it, adapts the real Telnyx WebRTC SDK to softphone-core.js,
    elects ONE leading tab per Business, and keeps a live call alive across in-app navigation.

    Markup contract for a Call button (Contacts and Conversations use the same one):
        <button data-mg-call data-contact-uid="…">           a contact
        <button data-mg-call data-conversation-input=".chat_id">   the open conversation's uid, read when needed

    A call is only ever started from the panel's own "Call" button after the person has seen who they
    are calling and from which number. Nothing here auto-answers a call.

    Boot: MGSoftphone.boot(config[, overrides]). `overrides` ({createClient, checkMicrophone}) exist so the
    browser smoke can drive a deterministic fake provider; the page never passes them.
==========================================================================================*/
(function (window, document) {
  "use strict";

  var Core = window.MGSoftphoneCore;
  var booted = false;
  var cfg, core, root, els = {}, preview = null, pjaxBusy = false, sdkPromise = null;
  var TAB = Math.random().toString(36).slice(2);

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function esc(text) { var d = document.createElement("div"); d.textContent = text == null ? "" : String(text); return d.innerHTML; }
  function store(op, key, value) {
    try { if (op === "get") { return window.localStorage.getItem(key); } if (op === "set") { window.localStorage.setItem(key, value); } else { window.localStorage.removeItem(key); } } catch (e) { return null; }
    return null;
  }

  /* ------------------------------------------------------------------ http */

  var api = {
    request: function (method, url, body) {
      var headers = { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": cfg.csrf };
      var init = { method: method, headers: headers, credentials: "same-origin" };
      if (body !== undefined) { headers["Content-Type"] = "application/json"; init.body = JSON.stringify(body); }
      return window.fetch(url, init).then(function (res) {
        return res.json().catch(function () { return null; }).then(function (json) { return { ok: res.ok && !(json && json.status === "error"), status: res.status, json: json }; });
      });
    },
    get: function (url) { return api.request("GET", url); },
    post: function (url, body) { return api.request("POST", url, body || {}); }
  };

  /* ------------------------------------------------------- Telnyx SDK adapter */

  function loadSdk() {
    if (window.TelnyxWebRTC && window.TelnyxWebRTC.TelnyxRTC) { return Promise.resolve(); }
    if (!sdkPromise) {
      sdkPromise = new Promise(function (resolve, reject) {
        var s = document.createElement("script");
        s.src = cfg.sdkUrl; s.async = true;
        s.onload = function () { window.TelnyxWebRTC && window.TelnyxWebRTC.TelnyxRTC ? resolve() : reject(new Error("sdk")); };
        s.onerror = function () { sdkPromise = null; reject(new Error("sdk")); };
        document.head.appendChild(s);
      });
    }
    return sdkPromise;
  }

  // Wraps the documented TelnyxRTC client and Call objects into the shape softphone-core expects.
  // One wrapper per SDK call (cached by id) so the core can compare identities.
  function createTelnyxClient(token, handlers) {
    var rtc = new window.TelnyxWebRTC.TelnyxRTC({ login_token: token });
    rtc.remoteElement = "mg-voice-audio";
    var wrappers = {};

    function wrap(call) {
      var w = wrappers[call.id];
      if (!w) {
        w = wrappers[call.id] = {
          raw: call,
          get state() { return call.state; },
          get direction() { return call.direction; },
          get remoteNumber() { return (call.options && (call.options.remoteCallerNumber || call.options.callerNumber)) || null; },
          get cause() { return call.cause; },
          get sipReason() { return call.sipReason; },
          answer: function () { return call.answer(); },
          hangup: function () { return call.hangup(); },
          muteAudio: function () { return call.muteAudio(); },
          unmuteAudio: function () { return call.unmuteAudio(); },
          hold: function () { return call.hold(); },
          unhold: function () { return call.unhold(); }
        };
      }
      return w;
    }

    var onReady = function () { handlers.ready(); };
    var onError = function (e) { handlers.error(e); };
    var onClose = function () { handlers.socketClose(); };
    var onNote = function (n) { if (n && n.type === "callUpdate" && n.call) { handlers.callUpdate(wrap(n.call)); } };
    rtc.on("telnyx.ready", onReady);
    rtc.on("telnyx.error", onError);
    rtc.on("telnyx.socket.error", onError);
    rtc.on("telnyx.socket.close", onClose);
    rtc.on("telnyx.notification", onNote);

    return {
      connect: function () { rtc.connect(); },
      disconnect: function () {
        ["telnyx.ready", "telnyx.error", "telnyx.socket.error", "telnyx.socket.close", "telnyx.notification"].forEach(function (ev) { try { rtc.off(ev); } catch (e) { /* ignore */ } });
        try { rtc.disconnect(); } catch (e) { /* ignore */ }
      },
      newCall: function (opts) { return wrap(rtc.newCall(opts)); }
    };
  }

  function checkMicrophone() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return Promise.resolve({ ok: false, code: "unsupported", message: "This browser can't access a microphone here. Use HTTPS and a current browser." });
    }
    return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      stream.getTracks().forEach(function (t) { t.stop(); });
      return { ok: true };
    }, function (e) {
      var name = e && e.name;
      if (name === "NotAllowedError" || name === "SecurityError") { return { ok: false, code: "denied", message: "Microphone access is blocked. Allow the microphone for this site in your browser's address bar, then try again." }; }
      if (name === "NotFoundError" || name === "OverconstrainedError") { return { ok: false, code: "missing", message: "No microphone was found. Connect one and try again." }; }
      return { ok: false, code: "error", message: "The microphone could not be used. Close other apps using it and try again." };
    });
  }

  /* ----------------------------------------------- one leading tab per Business */

  function makeLeader() {
    var key = "mg-voice-leader:" + cfg.businessUid, LEASE_MS = 60000, bc = null;
    function read() { try { return JSON.parse(store("get", key) || "null"); } catch (e) { return null; } }
    function write() { store("set", key, JSON.stringify({ tab: TAB, ts: Date.now() })); }
    try { bc = new window.BroadcastChannel(key); bc.onmessage = function (m) { if (m.data && m.data.type === "takeover" && m.data.tab !== TAB) { core.onLeaderLost(); } }; } catch (e) { bc = null; }
    window.addEventListener("storage", function (e) {
      if (e.key === key && e.newValue) { try { if (JSON.parse(e.newValue).tab !== TAB) { core.onLeaderLost(); } } catch (x) { /* ignore */ } }
    });
    return {
      tryAcquire: function () { var l = read(); if (l && l.tab !== TAB && Date.now() - l.ts < LEASE_MS) { return false; } write(); return true; },
      renew: function () { var l = read(); if (!l || l.tab === TAB) { write(); } },
      release: function () { var l = read(); if (l && l.tab === TAB) { store("del", key); } },
      takeOver: function () { write(); if (bc) { bc.postMessage({ type: "takeover", tab: TAB }); } }
    };
  }

  /* ------------------------------------------------------------------- panel */

  function buildPanel() {
    root = document.createElement("section");
    root.id = "mg-voice-root";
    root.className = "mg-voice";
    root.hidden = true;
    root.innerHTML =
      '<audio id="mg-voice-audio" autoplay playsinline></audio>' +
      '<div class="mg-voice-card" role="dialog" aria-live="polite" aria-label="Phone">' +
      '<div class="mg-voice-head"><span class="mg-voice-badge" data-el="badge"></span><button type="button" class="mg-voice-x" data-el="close" aria-label="Close">&times;</button></div>' +
      '<div class="mg-voice-who"><div class="mg-voice-name" data-el="name"></div><div class="mg-voice-num" data-el="number"></div><div class="mg-voice-from" data-el="from"></div></div>' +
      '<div class="mg-voice-timer" data-el="timer" hidden></div>' +
      '<div class="mg-voice-msg" data-el="msg" role="status"></div>' +
      '<div class="mg-voice-pick" data-el="pick" hidden><label>Call from <select data-el="select"></select></label></div>' +
      '<div class="mg-voice-note" data-el="noteBox" hidden><label>Call note (optional)<textarea data-el="note" maxlength="2000" rows="2"></textarea></label><button type="button" class="mg-voice-btn" data-el="saveNote">Save note</button></div>' +
      '<div class="mg-voice-actions" data-el="actions"></div>' +
      '</div>' +
      '<div class="mg-voice-strip" data-el="strip" hidden></div>';
    document.body.appendChild(root);
    root.querySelectorAll("[data-el]").forEach(function (n) { els[n.getAttribute("data-el")] = n; });
    els.close.addEventListener("click", function () { if (preview) { preview = null; } core.dismiss(); render(); });
    els.saveNote.addEventListener("click", function () {
      els.saveNote.disabled = true;
      core.saveNote(els.note.value).then(function (r) { els.saveNote.disabled = false; els.saveNote.textContent = r && r.ok ? "Note saved" : "Couldn't save — retry"; });
    });
  }

  function btn(label, cls, handler, attrs) {
    var b = document.createElement("button");
    b.type = "button"; b.className = "mg-voice-btn " + (cls || ""); b.textContent = label;
    Object.keys(attrs || {}).forEach(function (k) { b.setAttribute(k, attrs[k]); });
    b.addEventListener("click", handler);
    return b;
  }

  function fmt(sec) { var m = Math.floor(sec / 60), s = sec % 60; return (m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s; }
  var tick = null;

  function render() {
    var st = core.getState(), call = st.call;
    var showStrip = !call && !preview && (st.registration === "other_tab" || st.registration === "disconnected" || st.registration === "failed");

    els.strip.hidden = !showStrip;
    if (showStrip) {
      els.strip.textContent = st.registrationMessage || "";
      if (st.registration === "other_tab") { var t = btn("Use calling here", "mg-voice-link", function () { core.takeOver(); }); els.strip.appendChild(t); }
    }

    var open = !!(call || preview);
    root.hidden = !open && !showStrip;
    root.classList.toggle("is-open", open);
    els.actions.innerHTML = "";
    els.pick.hidden = true; els.noteBox.hidden = true; els.timer.hidden = true;
    root.classList.remove("mg-voice-incoming", "mg-voice-ended");
    els.close.hidden = !!(call && call.phase !== "ended"); // a live or ringing call is ended with its own buttons
    if (!open) { stopTick(); return; }

    if (preview && !call) { renderPreview(); return; }

    var phaseLabel = { dialing: "Connecting…", ringing: "Ringing…", incoming: "Incoming call", active: call.held ? "On hold" : "In call", ended: "Call ended" }[call.phase];
    els.badge.textContent = phaseLabel;
    els.name.textContent = call.name || call.number || "Unknown caller";
    els.number.textContent = call.name ? (call.number || "") : "";
    els.from.textContent = call.from ? (call.direction === "inbound" ? "To " : "From ") + call.from : "";
    els.msg.textContent = call.notice || (call.endReason ? call.endReason.message : "");
    els.msg.classList.toggle("is-error", !!(call.endReason && call.endReason.failed));

    if (call.phase === "incoming") {
      root.classList.add("mg-voice-incoming");
      els.actions.appendChild(btn("Answer", "mg-voice-ok", function () { core.answer(); }, { "data-role": "voice-answer" }));
      els.actions.appendChild(btn("Decline", "mg-voice-bad", function () { core.decline(); }, { "data-role": "voice-decline" }));
    } else if (call.phase === "ended") {
      root.classList.add("mg-voice-ended");
      stopTick();
      if (call.durationSeconds) { els.timer.hidden = false; els.timer.textContent = fmt(call.durationSeconds); }
      if (call.callUid) { els.noteBox.hidden = false; if (!call.noteSaved && document.activeElement !== els.note) { els.note.value = call.note || ""; } els.saveNote.textContent = call.noteSaved ? "Note saved" : "Save note"; }
      els.actions.appendChild(btn("Close", "", function () { core.dismiss(); render(); }, { "data-role": "voice-close" }));
    } else {
      els.actions.appendChild(btn(call.muted ? "Unmute" : "Mute", call.muted ? "is-on" : "", function () { core.toggleMute(); }, { "data-role": "voice-mute", "aria-pressed": String(!!call.muted) }));
      if (call.phase === "active") { els.actions.appendChild(btn(call.held ? "Resume" : "Hold", call.held ? "is-on" : "", function () { core.toggleHold(); }, { "data-role": "voice-hold" })); }
      els.actions.appendChild(btn(call.phase === "active" ? "End call" : "Cancel", "mg-voice-bad", function () { core.hangup(); }, { "data-role": "voice-end" }));
      if (call.phase === "active") { startTick(); }
    }
  }

  function startTick() {
    stopTick();
    var update = function () {
      var c = core.getState().call;
      if (!c || !c.answeredAt) { return; }
      els.timer.hidden = false;
      els.timer.textContent = fmt(Math.max(0, Math.floor((Date.now() - c.answeredAt) / 1000)));
    };
    update();
    tick = window.setInterval(update, 1000);
  }
  function stopTick() { if (tick) { window.clearInterval(tick); tick = null; } }

  function renderPreview() {
    var p = preview;
    els.badge.textContent = "New call";
    els.name.textContent = p.name || p.number || "Contact";
    els.number.textContent = p.name ? (p.number || "") : "";
    els.from.textContent = "";
    els.msg.classList.remove("is-error");

    if (p.loading) { els.msg.textContent = "Checking calling setup…"; return; }
    if (!p.callable) {
      els.msg.textContent = p.message || "Calling is not available here.";
      els.msg.classList.add("is-error");
      if (p.settingsUrl) { var a = document.createElement("a"); a.href = p.settingsUrl; a.textContent = "Open voice settings"; a.className = "mg-voice-link"; a.setAttribute("data-mg-no-pjax", ""); els.actions.appendChild(a); }
      els.actions.appendChild(btn("Close", "", function () { preview = null; render(); }));
      return;
    }

    els.msg.textContent = "";
    els.pick.hidden = false;
    els.select.innerHTML = p.numbers.map(function (n) { return '<option value="' + esc(n.uid) + '"' + (n.uid === p.selected ? " selected" : "") + ">" + esc(n.number) + "</option>"; }).join("");
    els.select.onchange = function () { p.selected = els.select.value; store("set", "mg-voice-from:" + cfg.businessUid, p.selected); };
    var go = btn("Call", "mg-voice-ok", function () {
      go.disabled = true; // a double click can never place two calls
      var n = p.numbers.filter(function (x) { return x.uid === p.selected; })[0];
      var req = { numberUid: p.selected, scope: n && n.scope, fromNumber: n && n.number, toNumber: p.number, name: p.name, contactUid: p.contactUid, conversationUid: p.conversationUid };
      preview = null;
      core.startCall(req).then(render);
      render();
    }, { "data-role": "voice-start" });
    els.actions.appendChild(go);
    els.actions.appendChild(btn("Cancel", "", function () { preview = null; render(); }));
  }

  /* -------------------------------------------------------- Call buttons */

  function targetOf(button) {
    var conv = button.getAttribute("data-conversation-input");
    var uid = conv ? ($(conv) || {}).value : button.getAttribute("data-conversation-uid");
    var contact = button.getAttribute("data-contact-uid");
    return contact ? { contactUid: contact } : (uid ? { conversationUid: uid } : null);
  }

  var optionCache = {};
  function options(target) {
    var k = (target.contactUid ? "c:" + target.contactUid : "v:" + target.conversationUid);
    var hit = optionCache[k];
    if (hit && Date.now() - hit.at < 20000) { return Promise.resolve(hit.value); }
    return core.loadOptions(target).then(function (v) { optionCache[k] = { at: Date.now(), value: v }; return v; });
  }

  function refreshButtons() {
    document.querySelectorAll("[data-mg-call]").forEach(function (b) {
      var t = targetOf(b), reason = b.parentNode && b.parentNode.querySelector(".mg-voice-reason[data-for]");
      if (!t) { setButton(b, false, "Open a conversation to call.", reason); return; }
      options(t).then(function (o) { setButton(b, !!o.callable, o.callable ? "" : (o.message || "Calling is not available."), reason, o.settings_url); });
    });
  }

  function setButton(b, ok, why, reasonEl, settingsUrl) {
    b.disabled = !ok; b.setAttribute("aria-disabled", String(!ok));
    b.title = ok ? "Call from your browser" : why;
    if (b.hasAttribute("data-mg-show-reason")) {
      var el = b.parentNode.querySelector(".mg-voice-reason");
      if (!el) { el = document.createElement("div"); el.className = "mg-voice-reason"; b.parentNode.appendChild(el); }
      el.textContent = ok ? "" : why;
      el.hidden = ok;
      if (!ok && settingsUrl) { var a = document.createElement("a"); a.href = settingsUrl; a.textContent = " Set up calling"; el.appendChild(a); }
    }
  }

  function onCallClick(button) {
    var t = targetOf(button);
    if (!t || core.inCall()) { return; }
    preview = { loading: true };
    render();
    options(t).then(function (o) {
      var remembered = store("get", "mg-voice-from:" + cfg.businessUid);
      var numbers = o.numbers || [];
      var selected = numbers.some(function (n) { return n.uid === remembered; }) ? remembered : (numbers[0] && numbers[0].uid);
      preview = {
        callable: !!o.callable, message: o.message, settingsUrl: o.settings_url, numbers: numbers, selected: selected,
        name: o.target && o.target.name, number: o.target && o.target.number, contactUid: t.contactUid, conversationUid: t.conversationUid
      };
      render();
    });
  }

  /* ------------------------------------------- navigation that keeps a call alive */

  function inBusinessScope(url) { return url.pathname.indexOf(cfg.businessPrefix) === 0; }

  function onLinkClick(e) {
    if (!core.inCall() || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
    var a = e.target.closest && e.target.closest("a[href]");
    if (!a || a.target || a.hasAttribute("download") || a.hasAttribute("data-mg-no-pjax") || a.closest("#mg-voice-root")) { return; }
    var url = new URL(a.href, window.location.href);
    if (url.origin !== window.location.origin || (url.pathname === window.location.pathname && url.search === window.location.search)) { return; }
    e.preventDefault();
    if (!inBusinessScope(url)) {
      if (window.confirm("You are on a call. Leaving this workspace will end it. Leave anyway?")) { window.location.assign(url.href); }
      return;
    }
    swapTo(url.href, true);
  }

  function swapTo(href, push) {
    if (pjaxBusy) { return; }
    pjaxBusy = true;
    window.fetch(href, { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "text/html" } }).then(function (res) {
      var ct = res.headers.get("content-type") || "";
      if (!res.ok || ct.indexOf("text/html") === -1 || new URL(res.url).origin !== window.location.origin) { throw new Error("fallback"); }
      return res.text().then(function (html) { return { html: html, url: res.url }; });
    }).then(function (r) {
      var next = new DOMParser().parseFromString(r.html, "text/html");
      var from = $(".app-content"), to = next.querySelector(".app-content");
      if (!from || !to) { throw new Error("fallback"); }
      mergeHead(next);
      document.title = next.title;
      document.body.className = next.body.className;
      ["data-col", "data-open", "data-menu"].forEach(function (a) { if (next.body.hasAttribute(a)) { document.body.setAttribute(a, next.body.getAttribute(a)); } });
      from.replaceWith(document.importNode(to, true));
      if (push) { window.history.pushState({ mgVoice: true }, "", r.url); }
      window.scrollTo(0, 0);
      return runScripts(next);
    }).then(function () {
      if (window.feather) { try { window.feather.replace({ width: 14, height: 14 }); } catch (e) { /* cosmetic */ } }
      document.dispatchEvent(new CustomEvent("mg:content-swapped"));
      refreshButtons();
    }).catch(function () {
      if (window.confirm("This page can't open without leaving the call view. Leaving will end your call. Leave anyway?")) { window.location.assign(href); }
    }).then(function () { pjaxBusy = false; });
  }

  function mergeHead(next) {
    var have = {};
    document.head.querySelectorAll("link[rel=stylesheet],style").forEach(function (n) { have[n.outerHTML] = true; });
    next.head.querySelectorAll("link[rel=stylesheet],style").forEach(function (n) { if (!have[n.outerHTML]) { document.head.appendChild(document.importNode(n, true)); } });
  }

  // Page scripts: those inside the new content area, and between the layout's page-script markers.
  // Library scripts already loaded (same src) are not loaded twice.
  function runScripts(next) {
    var list = [], loaded = {};
    document.querySelectorAll("script[src]").forEach(function (s) { loaded[s.src] = true; });
    function collect(container) { container.querySelectorAll("script").forEach(function (s) { if (s.type !== "application/x-mg-region" && list.indexOf(s) === -1) { list.push(s); } }); }
    collect(next.querySelector(".app-content"));
    ["vendor-scripts", "page-scripts"].forEach(function (name) {
      var s = next.querySelector('script[data-mg-region="' + name + ':start"]'), e = next.querySelector('script[data-mg-region="' + name + ':end"]');
      for (var n = s && s.nextSibling; n && n !== e; n = n.nextSibling) { if (n.nodeName === "SCRIPT") { list.push(n); } }
    });

    // Handlers registered for DOMContentLoaded/load would never fire on a swap: capture and run them once.
    var late = [], origDoc = document.addEventListener, origWin = window.addEventListener;
    document.addEventListener = function (type, fn, o) { if (type === "DOMContentLoaded") { late.push(fn); } else { origDoc.call(document, type, fn, o); } };
    window.addEventListener = function (type, fn, o) { if (type === "load") { late.push(fn); } else { origWin.call(window, type, fn, o); } };

    var chain = Promise.resolve();
    list.forEach(function (old) {
      chain = chain.then(function () {
        return new Promise(function (resolve) {
          if (old.src && loaded[old.src]) { return resolve(); }
          var s = document.createElement("script");
          if (old.src) { s.src = old.src; s.onload = s.onerror = function () { resolve(); }; document.body.appendChild(s); return; }
          s.textContent = old.textContent;
          try { document.body.appendChild(s); } catch (e) { /* a page script error must not drop the call */ }
          resolve();
        });
      });
    });
    return chain.then(function () {
      document.addEventListener = origDoc; window.addEventListener = origWin;
      late.forEach(function (fn) { try { fn.call(document, new Event("DOMContentLoaded")); } catch (e) { /* page error, call unaffected */ } });
    }, function () { document.addEventListener = origDoc; window.addEventListener = origWin; });
  }

  /* ------------------------------------------------------------------- boot */

  function boot(config, overrides) {
    if (booted) { return window.MGSoftphone; }
    booted = true;
    cfg = config;
    overrides = overrides || {};
    buildPanel();

    var leader = makeLeader();
    var secure = window.isSecureContext !== false && (window.location.protocol === "https:" || /^(localhost|127\.0\.0\.1)$/.test(window.location.hostname));
    core = Core.createSoftphone({
      api: api,
      urls: {
        session: cfg.urls.session, options: cfg.urls.options, token: cfg.urls.token, heartbeat: cfg.urls.heartbeat, calls: cfg.urls.calls, lookup: cfg.urls.lookup,
        clientEvent: function (uid) { return cfg.urls.callBase + "/" + encodeURIComponent(uid) + "/client-event"; },
        note: function (uid) { return cfg.urls.callBase + "/" + encodeURIComponent(uid) + "/note"; }
      },
      supported: overrides.supported !== undefined ? overrides.supported : !!(window.RTCPeerConnection && navigator.mediaDevices),
      isSecure: overrides.isSecure !== undefined ? overrides.isSecure : secure,
      prepare: overrides.createClient ? null : loadSdk,
      createClient: overrides.createClient || createTelnyxClient,
      checkMicrophone: overrides.checkMicrophone || checkMicrophone,
      leader: leader,
      now: function () { return Date.now(); },
      setTimeout: function (f, ms) { return window.setTimeout(f, ms); },
      clearTimeout: function (id) { window.clearTimeout(id); },
      setInterval: function (f, ms) { return window.setInterval(f, ms); },
      clearInterval: function (id) { window.clearInterval(id); },
      uuid: function () { return (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2); },
      beaconOffline: function () {
        try { var fd = new FormData(); fd.append("_token", cfg.csrf); if (navigator.sendBeacon) { navigator.sendBeacon(cfg.urls.offline, fd); } } catch (e) { /* best effort */ }
      }
    });
    core.subscribe(render);

    document.addEventListener("click", function (e) {
      var b = e.target.closest && e.target.closest("[data-mg-call]");
      if (b) { e.preventDefault(); if (!b.disabled) { onCallClick(b); } return; }
      onLinkClick(e);
    });
    window.addEventListener("popstate", function () { if (core.inCall()) { swapTo(window.location.href, false); } });
    window.addEventListener("beforeunload", function (e) { if (core.inCall()) { e.preventDefault(); e.returnValue = ""; } });
    // Real unloads (reload, close, logout, workspace switch) clean up: hang up, release the lead, mark offline.
    window.addEventListener("pagehide", function () { core.shutdown(); });
    document.addEventListener("click", function (e) {
      var a = e.target.closest && e.target.closest("a[href*='logout']");
      if (a) { core.shutdown(); }
    }, true);

    var ready = function () { refreshButtons(); core.init(); };
    if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", ready); } else { ready(); }

    window.MGSoftphone = { core: core, refreshButtons: refreshButtons, boot: boot };
    return window.MGSoftphone;
  }

  window.MGSoftphone = { boot: boot };
})(window, document);
