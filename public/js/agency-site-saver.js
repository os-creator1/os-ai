/*
 * White Label -> Landing Page editor: the save queue.
 *
 * Why it exists. The editor autosaves in place. Three properties must hold, whatever the timing:
 *
 *  1. What is recorded as "saved" is what was SUBMITTED, never what the form says when the response arrives. An edit
 *     made while a request is in flight is therefore still seen as unsaved and is sent next.
 *  2. Requests are applied by the server in the order they were made. They go through one queue, one at a time, so a
 *     slow earlier response can never overwrite a later edit (no out-of-order writes).
 *  3. Every save resolves to an explicit {ok, message} result and never rejects. Callers (Save draft, Publish) can
 *     therefore tell success from failure; a failed save leaves its values marked "unsaved" so they are sent again.
 *
 * "Expected" is what the server will hold once everything queued succeeds; it is what edits are diffed against, so
 * changing a value and changing it back while a request is in flight is still sent. If a request fails, the paths it
 * carried fall back to the last CONFIRMED value.
 *
 * Plain JS, no dependencies, usable from the page and from Node (tests/Js).
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) { module.exports = factory(); } else { root.AgencySiteSaver = factory(); }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    function json(value) { return JSON.stringify(value === undefined ? null : value); }

    /**
     * @param {{send: function(Object): Promise<{status:number, body:Object}>}} options
     *        send() performs one POST of {answers} and resolves with the HTTP status and parsed body (or rejects).
     */
    function create(options) {
        var confirmed = {};
        var expected = {};
        var chain = Promise.resolve();
        var inFlight = 0;
        var lastFailure = null;

        function remember(path, value) { var s = json(value); confirmed[path] = s; expected[path] = s; }

        /** Answers whose value differs from what the server will hold. Untouched fields are never sent. */
        function changed(answers) {
            var out = {};
            Object.keys(answers).forEach(function (path) {
                if (expected[path] !== json(answers[path])) { out[path] = answers[path]; }
            });
            return out;
        }

        /**
         * Queue a save of exactly these answers. The values are copied now, so later edits to the objects passed in
         * cannot change what is sent. Resolves {ok, message, body, answers}; never rejects.
         */
        function save(answers, extra) {
            var snapshot = JSON.parse(json(answers));
            var keys = Object.keys(snapshot);

            if (keys.length === 0) { return Promise.resolve({ ok: true, message: null, body: null, answers: snapshot }); }

            keys.forEach(function (path) { expected[path] = json(snapshot[path]); });
            inFlight++;

            var run = function () {
                return Promise.resolve()
                    .then(function () { return options.send(Object.assign({ answers: snapshot }, extra || {})); })
                    .then(function (res) {
                        var ok = res && res.status >= 200 && res.status < 300 && res.body && res.body.ok === true;

                        if (ok) {
                            keys.forEach(function (path) { confirmed[path] = json(snapshot[path]); });
                            lastFailure = null;

                            return { ok: true, message: null, body: res.body, answers: snapshot };
                        }

                        return failed((res && res.body && res.body.message) || 'The server could not save your changes.');
                    }, function () {
                        return failed('Could not reach the server — your changes are still on this page.');
                    });
            };

            function failed(message) {
                keys.forEach(function (path) {
                    // Only roll back if no newer queued save has since changed what is expected for this path.
                    if (expected[path] === json(snapshot[path])) { expected[path] = confirmed[path]; }
                });
                lastFailure = message;

                return { ok: false, message: message, body: null, answers: snapshot };
            }

            var result = chain.then(run);
            chain = result.then(function () { inFlight--; }, function () { inFlight--; });

            return result;
        }

        return {
            remember: remember,
            changed: changed,
            save: save,
            /** Resolves once every queued save has finished (successfully or not). */
            idle: function () { return chain; },
            pending: function () { return inFlight; },
            lastFailure: function () { return lastFailure; },
            confirmed: function (path) { return confirmed[path]; },
            expected: function (path) { return expected[path]; }
        };
    }

    return { create: create };
}));
