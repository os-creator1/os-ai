{{-- Progressive enhancement for the number chooser: toll-free search runs in place and morphs the page; without JS every form posts normally. --}}
<script>
    (function () {
        function syncPanels(form) {
            if (!form) { return; }
            var checked = form.querySelector('input[name="candidate_token"]:checked');
            var idx = checked ? checked.getAttribute('data-offer') : null;
            form.querySelectorAll('[data-offer-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-offer-panel') !== idx;
            });
        }
        document.addEventListener('change', function (e) {
            if (e.target.matches && e.target.matches('input[name="candidate_token"]')) { syncPanels(e.target.closest('#number-order-form')); }
            if (e.target.matches && e.target.matches('[data-flow-autosubmit]') && e.target.form) {
                if (e.target.form.requestSubmit) { e.target.form.requestSubmit(); } else { e.target.form.submit(); }
            }
        });
        syncPanels(document.getElementById('number-order-form'));

        var flow = document.querySelector('[data-role="number-flow"]');
        if (!flow || !window.fetch || !window.DOMParser) { return; }
        var choose = flow.querySelector('.pm-flow__choose');
        var results = flow.querySelector('.pm-flow__results');
        var body = flow.querySelector('[data-flow-body]');

        function setState(state, focusTarget) {
            flow.setAttribute('data-state', state);
            choose.inert = state === 'results';
            results.inert = state !== 'results';
            if (focusTarget) { window.setTimeout(function () { focusTarget.focus({ preventScroll: true }); }, 50); }
        }

        function skeleton() {
            return '<span class="pm-skeleton" style="margin-bottom:8px"></span><span class="pm-skeleton" style="margin-bottom:8px"></span><span class="pm-skeleton"></span>';
        }

        var seq = 0;
        var controller = null;
        var pending = null;

        // Re-enable exactly the buttons this search disabled (a button the server rendered disabled stays disabled).
        function release(form, disabledByUs) {
            if (!document.body.contains(form)) { return; }
            disabledByUs.forEach(function (b) { b.disabled = false; });
        }

        async function search(form) {
            // Only the newest search may touch the page: older ones are cancelled and ignored.
            var mine = ++seq;
            if (controller) { controller.abort(); }
            controller = window.AbortController ? new AbortController() : null;
            var disabledByUs = Array.prototype.filter.call(form.querySelectorAll('button'), function (b) { return !b.disabled; });
            disabledByUs.forEach(function (b) { b.disabled = true; });
            pending = { form: form, buttons: disabledByUs };
            body.setAttribute('aria-busy', 'true');
            if (flow.getAttribute('data-state') !== 'results') { body.innerHTML = skeleton(); setState('results'); }
            try {
                var response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller ? controller.signal : undefined });
                var doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                if (mine !== seq) { return; }
                var fresh = response.ok ? doc.querySelector('[data-flow-body]') : null;
                if (!fresh || !fresh.children.length) { throw new Error('no results'); }
                body.innerHTML = fresh.innerHTML;
                syncPanels(document.getElementById('number-order-form'));
                body.removeAttribute('aria-busy');
                release(form, disabledByUs);
                var first = body.querySelector('input[name="candidate_token"]');
                if (first) { first.focus({ preventScroll: true }); }
            } catch (err) {
                if (mine !== seq || (err && err.name === 'AbortError')) { return; }
                release(form, disabledByUs);
                if (window.console && console.debug) { console.debug('number search fell back to a normal request', err && err.message); }
                // Anything unexpected (an error message, a redirect): let the normal page flow show it.
                form.submit();
            } finally {
                if (mine === seq) { body.removeAttribute('aria-busy'); release(form, disabledByUs); pending = null; }
            }
        }

        document.addEventListener('submit', function (e) {
            var form = e.target.closest ? e.target.closest('[data-flow-search]') : null;
            if (!form) { return; }
            e.preventDefault();
            search(form);
        });
        flow.querySelector('[data-flow-reset]').addEventListener('click', function (e) {
            e.preventDefault();
            // Leaving the results cancels any search still in flight, so a late answer can't reopen or replace them.
            seq++;
            if (controller) { controller.abort(); }
            body.removeAttribute('aria-busy');
            if (pending) { release(pending.form, pending.buttons); pending = null; }
            setState('choose', choose.querySelector('[data-role="find-toll-free"]'));
        });
    })();
</script>
