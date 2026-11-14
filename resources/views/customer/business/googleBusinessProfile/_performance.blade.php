{{--
    Google performance. READ ONLY. A page view never calls Google: it shows the last successful read (kept at most 24 hours,
    encrypted, per location) and, when there is none, the page itself asks for one after it has loaded. Nothing Google sends is logged.

    Every value is Google's own count for the dates shown. A figure Google did not report for every day of a period is
    "Not available", never zero, and a change is shown only when both periods are complete. Google search-term counts are
    impressions (how often the business appeared for a search), NOT ranking positions. Escaped Blade output only.
--}}

<section class="gbp-cd is-clip" data-role="gbp-performance">
    <div class="gbp-ch">
        <b>Google performance</b>
        <span class="gbp-ch-note">vs previous period</span>
        @if (! empty($canManageGoogle))
            <form method="POST" data-gbp-perf-refresh action="{{ route('customer.workspaces.businesses.gbp.performance', [$workspaceUid, $businessUid]) }}">
                @csrf
                <input type="hidden" name="refresh" value="1">
                <button type="submit" class="gbp-btn is-outline" style="min-height:34px;padding:0 12px;font-size:13px"><x-ds-icon name="refresh-cw" :size="14" /> Refresh Google performance</button>
            </form>
        @endif
    </div>

    <div id="gbp-performance-body" class="gbp-perf-body"
         data-csrf="{{ csrf_token() }}"
         data-url="{{ route('customer.workspaces.businesses.gbp.performance', [$workspaceUid, $businessUid]) }}"
         @if (! empty($performanceAuto)) data-auto="1" @endif>
        @include('customer.business.googleBusinessProfile._performance-body')
    </div>
</section>

<script>
    (function () {
        var box = document.getElementById('gbp-performance-body');
        if (!box || !window.fetch) { return; }

        function load(refresh) {
            var body = new FormData();
            if (refresh) { body.append('refresh', '1'); }
            box.setAttribute('aria-busy', 'true');
            if (refresh) { box.insertAdjacentHTML('afterbegin', '<p class="gbp-perf-msg" role="status">Refreshing from Google&hellip;</p>'); }

            return fetch(box.getAttribute('data-url'), {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': box.getAttribute('data-csrf'), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
            }).then(function (response) {
                if (!response.ok) { throw new Error('status'); }
                return response.text();
            }).then(function (html) {
                box.innerHTML = html;
            }).catch(function () {
                box.innerHTML = '<p class="gbp-perf-msg is-warn">Google performance could not be loaded right now. Use Refresh to try again.</p>';
            }).then(function () { box.removeAttribute('aria-busy'); });
        }

        if (box.getAttribute('data-auto') === '1') { load(false); }

        var form = document.querySelector('form[data-gbp-perf-refresh]');
        if (form) {
            form.addEventListener('submit', function (event) { event.preventDefault(); load(true); });
        }
    })();
</script>
<style>
    .gbp-perf-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3); }
    @media (min-width: 992px) { .gbp-perf-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    .gbp-perf-kpi { display: flex; flex-direction: column; gap: var(--space-1); padding: var(--space-3); border: 1px solid var(--color-border-neutral); border-radius: var(--radius-lg); background: var(--color-surface-secondary); }
    .gbp-perf-value { font-size: 1.5rem; font-weight: 600; color: var(--color-text-primary); line-height: 1.2; }
    .gbp-perf-terms { list-style: none; margin: 0 0 var(--space-2); padding: 0; }
    .gbp-perf-terms li { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--space-2); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border-neutral); }
</style>
