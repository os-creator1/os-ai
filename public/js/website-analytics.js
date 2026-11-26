/*
 * MotionGrove native website analytics (published pages only). First-party, no Google Analytics account needed.
 *
 * Reads the inert JSON block #mg-analytics-config (built server-side; never emitted for a preview/draft/editor render) and
 * reports four anonymous funnel events to MotionGrove's own collection endpoint:
 *   pageview       when the page is actually VISIBLE to a person (a prerender, prefetch or link-preview fetch never runs this)
 *   calendar_open  when the booking calendar is actually DISPLAYED (embedded on the page, or the standalone calendar page)
 *   form_open      when the embedded lead form is actually DISPLAYED
 *   form_inquiry   when the Forms thank-you page has announced a STORED submission (same verified, origin-checked signal the
 *                  conversion tracking uses); the server re-checks that the submission exists for this site's Business
 * A confirmed BOOKING is never reported from here: the server counts it after the appointment is stored. This script only
 * hands the booking form the anonymous context (mg_pk, mg_v, mg_s, mg_c) so the server can attribute it.
 *
 * Privacy: nothing is sent until consent allows (when the site's Tracking setting requires consent), never with Global Privacy
 * Control or Do Not Track, and never after the visitor declined. Only a random visitor id, a random visit id, the page path (no
 * query string), the referring HOST and an opaque campaign value are sent: no phone number, e-mail, name or IP is read.
 *
 * Attribution carried across routes/tabs by URL parameters on links to the booking calendar: mgp (site key), mgv, mgs, mgc.
 */
(function () {
    'use strict';

    var CONSENT_KEY = 'mg_tracking_consent';
    var VISITOR_KEY = 'mg_vid';
    var SESSION_KEY = 'mg_sid';
    var SESSION_AT_KEY = 'mg_sid_at';
    var HANDOFF_KEY = 'mg_hs';
    var CAMPAIGN_KEY = 'mg_cid';
    var IDLE_MS = 30 * 60 * 1000;
    var HEX32 = /^[a-f0-9]{32}$/;
    var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    var BOOK_PATH = /\/book\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:$|[\/?#])/i;

    var node = document.getElementById('mg-analytics-config');
    if (!node) { return; }
    var config;
    try { config = JSON.parse(node.textContent || ''); } catch (e) { return; }
    if (!config || typeof config !== 'object' || !config.key || !config.endpoint) { return; }

    // ---- never when the visitor opted out of tracking
    if (navigator.globalPrivacyControl === true || navigator.doNotTrack === '1' || window.doNotTrack === '1') { return; }

    var started = false;
    var mem = {};

    function store(kind, key, value) {
        try {
            var area = kind === 'local' ? window.localStorage : window.sessionStorage;
            if (value === undefined) { return area.getItem(key); }
            area.setItem(key, value);
            return value;
        } catch (e) { /* storage blocked: memory only */ }
        if (value === undefined) { return mem[kind + key] || null; }
        mem[kind + key] = value;
        return value;
    }

    function randomHex() {
        var bytes = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }

    function consent() { return store('local', CONSENT_KEY); }
    function allowed() {
        if (consent() === 'denied') { return false; }
        return !config.require_consent || consent() === 'granted';
    }

    // ---- URL handoff (another route or a new tab)
    var params;
    try { params = new URLSearchParams(window.location.search); } catch (e) { params = { get: function () { return null; } }; }
    function param(name, pattern) { var v = params.get(name); return v && pattern.test(v) ? v : null; }

    function visitorId() {
        // A validated handoff (from the originating site's own link) wins over whatever this origin already stored, so the
        // calendar page continues the SAME browser instead of counting a second one.
        var handed = param('mgv', HEX32);
        var v = store('local', VISITOR_KEY);
        if (handed) { v = handed; store('local', VISITOR_KEY, v); }
        else if (!HEX32.test(v || '')) { v = randomHex(); store('local', VISITOR_KEY, v); }
        return v;
    }

    function sessionId() {
        var s = store('session', SESSION_KEY);
        var at = parseInt(store('session', SESSION_AT_KEY) || '0', 10);
        var now = Date.now();
        var handed = param('mgs', HEX32);
        var fresh = false;
        if (handed && store('session', HANDOFF_KEY) !== handed) {
            // First time this handoff is seen in this tab: it wins over unrelated storage. Reusing the SAME handoff URL later
            // (refresh, revisit) is not a new handoff, so that visit can expire like any other.
            s = handed; store('session', HANDOFF_KEY, handed);
        } else if (!HEX32.test(s || '') || (now - at) > IDLE_MS) {
            s = randomHex(); fresh = true;
        }
        // A NEW visit starts with no inherited campaign: only an incoming mgc (read by campaign()) can give it one.
        if (fresh) { store('session', CAMPAIGN_KEY, ''); }
        store('session', SESSION_KEY, s);
        store('session', SESSION_AT_KEY, String(now));
        return s;
    }

    function campaign() {
        var fromUrl = param('mgc', /^c[a-z0-9]{9}$/);
        if (fromUrl) { store('session', CAMPAIGN_KEY, fromUrl); return fromUrl; }
        var kept = store('session', CAMPAIGN_KEY);
        return kept && /^c[a-z0-9]{9}$/.test(kept) ? kept : null;
    }

    function send(kind, extra) {
        var body = {
            k: config.key, t: kind, v: visitorId(), s: sessionId(), e: randomHex(),
            p: window.location.pathname, r: document.referrer || '', c: campaign()
        };
        Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });
        var json = JSON.stringify(body);
        try {
            fetch(config.endpoint, { method: 'POST', body: json, headers: { 'Content-Type': 'text/plain' }, mode: 'cors', credentials: 'omit', keepalive: true }).catch(function () {});
        } catch (e) {
            try { navigator.sendBeacon(config.endpoint, new Blob([json], { type: 'text/plain' })); } catch (e2) { /* ignore */ }
        }
    }

    // ---- links to the booking calendar carry the anonymous context to another route or tab
    function decorate(a) {
        var href = a.getAttribute('href');
        if (!href || !BOOK_PATH.test(href)) { return; }
        var url;
        try { url = new URL(href, window.location.href); } catch (e) { return; }
        if (!BOOK_PATH.test(url.pathname)) { return; }
        // Only MotionGrove's own calendar: this page's origin or the collection endpoint's origin, never a third-party scheduler.
        var own = [window.location.origin];
        try { own.push(new URL(config.endpoint, window.location.href).origin); } catch (e) { /* ignore */ }
        if (own.indexOf(url.origin) === -1) { return; }
        url.searchParams.set('mgp', config.key);
        url.searchParams.set('mgv', visitorId());
        url.searchParams.set('mgs', sessionId());
        var c = campaign();
        if (c) { url.searchParams.set('mgc', c); }
        a.setAttribute('href', url.toString());
    }

    function decorateAll() { Array.prototype.forEach.call(document.querySelectorAll('a[href]'), decorate); }

    function onLinkIntent(event) {
        var el = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (el) { decorate(el); }
    }

    // ---- the booking form gets the anonymous context so the SERVER can attribute the booking it stores
    function annotateBookingForm(form) {
        var fields = { mg_pk: config.key, mg_v: visitorId(), mg_s: sessionId(), mg_c: campaign() || '' };
        Object.keys(fields).forEach(function (name) {
            var input = form.querySelector('input[type="hidden"][name="' + name + '"]');
            if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = name; form.appendChild(input); }
            input.value = fields[name];
        });
    }

    function onSubmit(event) {
        var form = event.target;
        if (form && form.tagName === 'FORM' && /\/book\/[0-9a-f-]{36}/i.test(form.getAttribute('action') || '')) { annotateBookingForm(form); }
    }

    // ---- display detection
    function whenDisplayed(el, callback) {
        if (!el) { return; }
        if (!('IntersectionObserver' in window)) { callback(); return; }
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting && entry.intersectionRatio > 0) { observer.disconnect(); callback(); }
            });
        }, { threshold: 0.1 });
        observer.observe(el);
    }

    function bookingTypeOf(app) {
        try {
            var cfg = JSON.parse(app.getAttribute('data-config') || '{}');
            var m = BOOK_PATH.exec(String(cfg.storeUrl || ''));
            if (m) { return m[1]; }
        } catch (e) { /* ignore */ }
        return config.booking_type && UUID.test(config.booking_type) ? config.booking_type : null;
    }

    function watchCalendar() {
        var app = document.querySelector('[data-role="public-scheduler"]');
        if (!app) { return; }
        var type = bookingTypeOf(app);
        if (!type) { return; }
        whenDisplayed(app, function () { send('calendar_open', { bt: type }); });
    }

    function watchForm() {
        var frame = document.querySelector('iframe[data-mg-lead-form]');
        if (frame) { whenDisplayed(frame, function () { send('form_open'); }); }
    }

    // ---- a stored form submission announced by the Forms thank-you page (origin-, frame- and deployment-checked)
    var inquiriesSent = {};
    function leadFrame(event) {
        var frames = document.querySelectorAll('iframe[data-mg-lead-form]');
        for (var i = 0; i < frames.length; i++) { if (frames[i].contentWindow === event.source) { return frames[i]; } }
        return null;
    }
    window.addEventListener('message', function (event) {
        if (!started) { return; }
        var frame = leadFrame(event);
        if (!frame) { return; }
        var origin;
        try { origin = new URL(frame.src, window.location.href).origin; } catch (e) { return; }
        if (event.origin !== origin) { return; }
        var data = event.data;
        if (!data || typeof data !== 'object' || data.type !== 'motiongrove:lead_submitted' || data.version !== 1) { return; }
        if (typeof data.submission_uid !== 'string' || !UUID.test(data.submission_uid)) { return; }
        if (data.deployment_uid !== frame.getAttribute('data-deployment-uid')) { return; }
        var uid = data.submission_uid.toLowerCase();
        if (inquiriesSent[uid]) { return; }
        inquiriesSent[uid] = true;
        send('form_inquiry', { sub: uid });
    });

    // ---- start: only once the page is really visible and consent allows
    function begin() {
        if (started) { return; }
        started = true;
        send('pageview');
        decorateAll();
        document.addEventListener('mousedown', onLinkIntent, true);
        document.addEventListener('touchstart', onLinkIntent, true);
        document.addEventListener('focusin', onLinkIntent, true);
        document.addEventListener('keydown', onLinkIntent, true);
        document.addEventListener('submit', onSubmit, true);
        watchCalendar();
        watchForm();
    }

    function whenVisible(callback) {
        if (document.prerendering) { document.addEventListener('prerenderingchange', function () { whenVisible(callback); }, { once: true }); return; }
        if (document.visibilityState === 'visible') { callback(); return; }
        document.addEventListener('visibilitychange', function handler() {
            if (document.visibilityState === 'visible') { document.removeEventListener('visibilitychange', handler); callback(); }
        });
    }

    function banner() {
        var bar = document.createElement('div');
        bar.setAttribute('role', 'dialog');
        bar.setAttribute('aria-label', 'Cookie preferences');
        bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:2147483000;display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:center;padding:.9rem 1rem;background:#fff;color:#222;border-top:1px solid #ddd;font:14px/1.4 system-ui,sans-serif;';
        var text = document.createElement('span');
        text.textContent = 'We use cookies to measure how this site performs and which ads bring enquiries.';
        function button(label, value) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = label;
            b.style.cssText = 'padding:.45rem .9rem;border:1px solid #222;border-radius:4px;background:' + (value === 'granted' ? '#222' : '#fff') + ';color:' + (value === 'granted' ? '#fff' : '#222') + ';cursor:pointer;font:inherit;';
            b.addEventListener('click', function () {
                store('local', CONSENT_KEY, value);
                bar.remove();
                window.dispatchEvent(new CustomEvent('mg:consent', { detail: value }));
            });
            return b;
        }
        bar.appendChild(text);
        bar.appendChild(button('Accept', 'granted'));
        bar.appendChild(button('Decline', 'denied'));
        document.body.appendChild(bar);
    }

    function init() {
        if (allowed()) { whenVisible(begin); return; }
        if (consent() === 'denied') { return; }
        // Consent required and not yet given: wait. The tracking loader shows the bar when it is on the page; otherwise we do.
        window.addEventListener('mg:consent', function (e) { if (e.detail === 'granted') { whenVisible(begin); } });
        if (config.own_banner && consent() === null) { banner(); }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
