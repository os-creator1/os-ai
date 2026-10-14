{{--
    Live progress for a running check, without full-page reloads and without hammering the server.

      - While a check runs, GET the status endpoint (one tiny JSON row) every 4 seconds, backing off to 15 seconds
        after the first minute, pausing while the tab is hidden, and giving up after 15 minutes.
      - It updates the stage, counters and bar in place.
      - When the check is no longer running, the same page is fetched again and #external-live is swapped in place
        (the header bar, banner and results), so the finished report appears without a reload. If that fails for any
        reason it falls back to a normal reload.
      - Forms marked data-live-post (Check again now / Cancel) submit by fetch and refresh the same region; if the
        fetch fails they submit normally, so the buttons work even without this script.
--}}
@verbatim
<script>
    (function () {
        'use strict';

        var region = document.getElementById('external-live');

        if (!region || window.__externalLive) {
            return;
        }

        window.__externalLive = true;

        var statusUrl = region.getAttribute('data-status-url');
        var started = Date.now();
        var timer = null;
        var lastToken = null;

        function isActive() {
            return !!region.querySelector('[data-live-status][data-active="1"]');
        }

        function delay() {
            var elapsed = Date.now() - started;

            return elapsed < 60000 ? 4000 : (elapsed < 180000 ? 8000 : 15000);
        }

        function setText(selector, value) {
            var node = region.querySelector('[data-live="' + selector + '"]');

            if (node && value !== undefined && value !== null) {
                node.textContent = String(value);
            }
        }

        function refreshRegion() {
            return fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (response) {
                    if (!response.ok) { throw new Error('refresh failed'); }
                    return response.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.getElementById('external-live');

                    if (!fresh) { throw new Error('region missing'); }

                    region.innerHTML = fresh.innerHTML;
                    started = Date.now();

                    // "We are checking your website now" is no longer true once the check has finished.
                    var flash = document.querySelector('[data-role="live-flash"]');

                    if (flash && !isActive()) {
                        flash.remove();
                    }

                    schedule();
                })
                .catch(function () { window.location.reload(); });
        }

        function poll() {
            timer = null;

            if (document.hidden || !isActive()) {
                schedule();

                return;
            }

            if (Date.now() - started > 15 * 60 * 1000) {
                return; // give up quietly; a manual refresh still works
            }

            fetch(statusUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (response) {
                    if (!response.ok) { throw new Error('status failed'); }
                    return response.json();
                })
                .then(function (s) {
                    if (s.active) {
                        setText('stage', s.stage_label);
                        setText('discovered', s.discovered);
                        setText('checked', s.checked);

                        var bar = region.querySelector('[data-live="bar"]');

                        if (bar && s.budget > 0) {
                            bar.style.width = Math.max(3, Math.min(100, Math.round(s.checked / s.budget * 100))) + '%';
                        }

                        schedule();
                    } else if (s.token !== lastToken) {
                        lastToken = s.token;
                        refreshRegion();
                    } else {
                        schedule();
                    }
                })
                .catch(function () { schedule(); });
        }

        function schedule() {
            if (timer === null && isActive()) {
                timer = setTimeout(poll, delay());
            }
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && isActive() && timer === null) {
                timer = setTimeout(poll, 500);
            }
        });

        region.addEventListener('submit', function (event) {
            var form = event.target;

            if (!form || !form.hasAttribute || !form.hasAttribute('data-live-post') || event.defaultPrevented) {
                return;
            }

            event.preventDefault();

            var button = form.querySelector('button[type="submit"]');

            if (button) { button.disabled = true; }

            fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok && response.status !== 422) { throw new Error('post failed'); }
                    return response.json();
                })
                .then(function (result) {
                    if (result && result.message) {
                        var flash = document.createElement('div');
                        flash.className = 'alert alert-' + (result.status === 'error' ? 'danger' : (result.status === 'success' ? 'success' : 'secondary')) + ' mb-2';
                        flash.setAttribute('role', 'status');
                        flash.setAttribute('data-role', 'live-flash');
                        flash.textContent = result.message;
                        var previous = document.querySelector('[data-role="live-flash"]');
                        if (previous) { previous.remove(); }
                        region.parentNode.insertBefore(flash, region);
                    }

                    started = Date.now();
                    return refreshRegion();
                })
                .catch(function () { form.submit(); });
        });

        // A check is already running when the page opens: start following it.
        schedule();
    })();
</script>
@endverbatim
