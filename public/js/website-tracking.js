/*
 * MotionGrove website tracking loader (published pages only).
 *
 * Reads the inert JSON block #mg-tracking-config (built server-side from the owner's validated Website → Tracking settings),
 * waits for visitor consent when the setting requires it, loads each Google tag / Meta Pixel exactly once, and turns a
 * VERIFIED successful lead-form submission into one conversion.
 *
 * What counts as a successful submission: the Forms thank-you page (shown only after the server stored the submission) posts
 * { type: 'motiongrove:lead_submitted', version: 1, deployment_uid, submission_uid } to its parent. This script accepts that
 * message only from the iframe it embedded for the lead form, from that iframe's own origin, for that iframe's own
 * deployment, with a UUID submission id — and only once per submission id (a browser re-notification, a reload of the thank-you
 * page or a replayed submission carries the same id). Clicking Submit, validation errors, spam (which has no submission id),
 * an opened form or a questionnaire page never post it. The message and the analytics payloads carry no personal data.
 *
 * A form inquiry is a LEAD (Google Ads conversion label, Meta "Lead", dataLayer "motiongrove_lead"). It is never reported as
 * a purchase or a confirmed booking.
 */
(function () {
    'use strict';

    var CONSENT_KEY = 'mg_tracking_consent';
    var SENT_KEY = 'mg_tracking_sent';
    var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    var configNode = document.getElementById('mg-tracking-config');
    if (!configNode) { return; }

    var config;
    try { config = JSON.parse(configNode.textContent || ''); } catch (e) { return; }
    if (!config || typeof config !== 'object') { return; }

    var loaded = false;
    var pending = [];
    var sent = {};

    function store(kind, key, value) {
        try {
            var area = kind === 'local' ? window.localStorage : window.sessionStorage;
            if (value === undefined) { return area.getItem(key); }
            area.setItem(key, value);
        } catch (e) { /* storage blocked: fall back to memory */ }
        return null;
    }

    function consent() { return store('local', CONSENT_KEY); }

    function allowed() { return !config.require_consent || consent() === 'granted'; }

    // ---- loading (once per id)
    function loadGoogle() {
        var ids = config.google_tag_ids || [];
        if (!ids.length) { return; }
        window.dataLayer = window.dataLayer || [];

        // GTM containers are NOT Google tag measurement IDs. Loading a GTM-… id through
        // gtag/js silently fails to install the container. Use gtm.js for each GTM id.
        var containers = ids.filter(function (id) { return /^GTM-/.test(id); });
        var tags = ids.filter(function (id) { return !/^GTM-/.test(id); });

        containers.forEach(function (id) {
            window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
            var containerScript = document.createElement('script');
            containerScript.async = true;
            containerScript.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(id);
            document.head.appendChild(containerScript);
        });

        if (!tags.length) { return; }
        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
        var script = document.createElement('script');
        script.async = true;
        script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(tags[0]);
        document.head.appendChild(script);
        window.gtag('js', new Date());
        tags.forEach(function (id) { window.gtag('config', id); });
    }

    function loadMeta() {
        var ids = config.meta_pixel_ids || [];
        if (!ids.length) { return; }
        if (!window.fbq) {
            var f = window, b = document, n;
            n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
            if (!f._fbq) { f._fbq = n; }
            n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
            var t = b.createElement('script');
            t.async = true;
            t.src = 'https://connect.facebook.net/en_US/fbevents.js';
            b.head.appendChild(t);
        }
        ids.forEach(function (id) { window.fbq('init', id); });
        window.fbq('track', 'PageView');
    }

    function loadSnippets() {
        (config.snippets || []).forEach(function (html) {
            var holder = document.createElement('template');
            holder.innerHTML = html;
            Array.prototype.forEach.call(holder.content.children, function (node) {
                var tag = node.tagName.toLowerCase();
                if (tag === 'noscript' || tag === 'img') { return; }
                if (tag === 'script') {
                    var copy = document.createElement('script');
                    Array.prototype.forEach.call(node.attributes, function (a) { copy.setAttribute(a.name, a.value); });
                    copy.text = node.text;
                    document.head.appendChild(copy);
                } else {
                    document.head.appendChild(node.cloneNode(true));
                }
            });
        });
    }

    function loadAll() {
        if (loaded) { return; }
        loaded = true;
        loadGoogle();
        loadMeta();
        loadSnippets();
        pending.splice(0).forEach(fire);
    }

    // ---- conversion (a verified lead, never a booking)
    function utm() {
        var out = {};
        try {
            var params = new URLSearchParams(window.location.search);
            ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function (k) {
                var v = params.get(k);
                if (v) { out[k] = v.slice(0, 100); }
            });
        } catch (e) { /* ignore */ }
        return out;
    }

    function fire(lead) {
        if (window.gtag) {
            (config.ads_conversions || []).forEach(function (c) {
                window.gtag('event', 'conversion', { send_to: c.id + '/' + c.label });
            });
        }
        if (window.fbq && (config.meta_pixel_ids || []).length) { window.fbq('track', 'Lead'); }
        window.dataLayer = window.dataLayer || [];
        var payload = { event: 'motiongrove_lead', form_uid: lead.formUid, page_path: window.location.pathname };
        var campaign = utm();
        Object.keys(campaign).forEach(function (k) { payload[k] = campaign[k]; });
        window.dataLayer.push(payload);
    }

    function record(lead) {
        if (sent[lead.submissionUid] || store('session', SENT_KEY + ':' + lead.submissionUid) === '1') { return; }
        sent[lead.submissionUid] = true;
        store('session', SENT_KEY + ':' + lead.submissionUid, '1');

        if (config.require_consent && consent() === 'denied') { return; }
        if (loaded) { fire(lead); } else { pending.push(lead); }
    }

    function leadFrame(event) {
        var frames = document.querySelectorAll('iframe[data-mg-lead-form]');
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].contentWindow === event.source) { return frames[i]; }
        }
        return null;
    }

    window.addEventListener('message', function (event) {
        var frame = leadFrame(event);
        if (!frame) { return; }
        var origin;
        try { origin = new URL(frame.src, window.location.href).origin; } catch (e) { return; }
        if (event.origin !== origin) { return; }
        var data = event.data;
        if (!data || typeof data !== 'object' || data.type !== 'motiongrove:lead_submitted' || data.version !== 1) { return; }
        if (typeof data.submission_uid !== 'string' || !UUID.test(data.submission_uid)) { return; }
        if (data.deployment_uid !== frame.getAttribute('data-deployment-uid')) { return; }
        record({ submissionUid: data.submission_uid.toLowerCase(), formUid: frame.getAttribute('data-form-uid') || '' });
    });

    // ---- consent
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
                if (value === 'granted') { loadAll(); } else { pending.length = 0; }
            });
            return b;
        }
        bar.appendChild(text);
        bar.appendChild(button('Accept', 'granted'));
        bar.appendChild(button('Decline', 'denied'));
        document.body.appendChild(bar);
    }

    function start() {
        if (allowed()) { loadAll(); return; }
        if (consent() === null) { banner(); }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
}());
