{{--
    Google performance. READ ONLY. A page view never calls Google: it shows the last successful read (kept at most 24 hours,
    encrypted, per location) and, when there is none, the page itself asks for one after it has loaded. Nothing Google sends is logged.

    Every value is Google's own count for the dates shown. A figure Google did not report for every day of a period is
    "Not available", never zero, and a change is shown only when both periods are complete. Google search-term counts are
    impressions (how often the business appeared for a search), NOT ranking positions. Escaped Blade output only.
--}}

<x-card :padded="false" class="gbp-card mb-2" data-role="gbp-performance">
    <div class="gbp-card-body">
        <div class="gbp-diff-head">
            <div>
                <p class="gbp-eyebrow mb-25">On Google</p>
                <h2 class="h5 mb-25">Google performance</h2>
                <p class="text-caption mb-0">How people found and contacted your business through Google Search and Maps.</p>
            </div>
            @if (! empty($canManageGoogle))
                <form method="POST" data-gbp-perf-refresh action="{{ route('customer.workspaces.businesses.gbp.performance', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    <input type="hidden" name="refresh" value="1">
                    <button type="submit" class="btn btn-outline-primary">Refresh Google performance</button>
                </form>
            @endif
        </div>

        <div id="gbp-performance-body"
             data-csrf="{{ csrf_token() }}"
             data-url="{{ route('customer.workspaces.businesses.gbp.performance', [$workspaceUid, $businessUid]) }}"
             @if (! empty($performanceAuto)) data-auto="1" @endif>
            @include('customer.business.googleBusinessProfile._performance-body')
        </div>
    </div>
</x-card>

<script>
    (function () {
        var box = document.getElementById('gbp-performance-body');
        if (!box || !window.fetch) { return; }

        function load(refresh) {
            var body = new FormData();
            if (refresh) { body.append('refresh', '1'); }
            box.setAttribute('aria-busy', 'true');
            if (refresh) { box.insertAdjacentHTML('afterbegin', '<p class="text-caption mb-1" role="status">Refreshing from Google&hellip;</p>'); }

            return fetch(box.getAttribute('data-url'), {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': box.getAttribute('data-csrf'), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
            }).then(function (response) {
                if (!response.ok) { throw new Error('status'); }
                return response.text();
            }).then(function (html) {
                box.innerHTML = html;
            }).catch(function () {
                box.innerHTML = '<p class="text-caption mb-0">Google performance could not be loaded right now. Use Refresh to try again.</p>';
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
