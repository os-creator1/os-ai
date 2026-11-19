/*
 * Sizes an embedded Forms-module form to its content. The form page (public/forms/_frame-height) posts
 * { type: 'motiongrove:form_height', version: 1, deployment_uid, height }; this script applies the height to the iframe it came from —
 * and only that one: the message must come from that iframe's own window, from its own origin, for its own deployment, and be a
 * sane number. Anything else is ignored. Without this script (or JavaScript) the iframe keeps its fixed fallback height.
 */
(function () {
    'use strict';

    var MIN = 240;
    var MAX = 6000;

    window.addEventListener('message', function (event) {
        var data = event.data;
        if (!data || typeof data !== 'object' || data.type !== 'motiongrove:form_height' || data.version !== 1) { return; }
        if (typeof data.height !== 'number' || !isFinite(data.height)) { return; }

        var frames = document.querySelectorAll('iframe[data-deployment-uid]');
        for (var i = 0; i < frames.length; i++) {
            var frame = frames[i];
            if (frame.contentWindow !== event.source) { continue; }

            var origin;
            try { origin = new URL(frame.src, window.location.href).origin; } catch (e) { return; }
            if (event.origin !== origin || data.deployment_uid !== frame.getAttribute('data-deployment-uid')) { return; }

            frame.style.height = Math.min(MAX, Math.max(MIN, Math.ceil(data.height))) + 'px';
            return;
        }
    });
}());
