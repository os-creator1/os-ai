{{--
    The state of the check, in the owner's words, on every tab (the header with the primary action is _header). Variables: $latest (newest crawl of the CURRENT
    address, any status, or null), $report (newest finished report of the current address, or null: the results the
    tab below describes), $crawlUrl, $cancelUrl, $settingsUrl.

    A running check never hides the previous report: the report stays below, labelled with its own date.
--}}
@php
    use App\Library\ExternalSite\ExternalAuditPresenter as P;

    $report = $report ?? null;
    $active = $latest !== null && $latest->isActive();
    $finishedLatest = $latest !== null && ! $active;
    $failure = $latest !== null && $latest->status === 'failed' ? P::failure($latest) : null;
    $reportIsLatest = $report !== null && $latest !== null && $report->id === $latest->id;
    $budget = (int) ($latest?->page_budget ?? 0);
@endphp

@if($active)
    @php
        $pct = $budget > 0 ? min(100, (int) round(($latest->progress_checked / max(1, $budget)) * 100)) : 0;
    @endphp
    <section class="xw-banner is-info" data-role="status-running" data-live-status data-active="1">
        <div class="xw-rw-title">
            <strong data-live="stage">{{ P::stageLabel($latest->stage) }}</strong>
            <span class="xw-mut">&middot; started {{ ($latest->started_at ?? $latest->created_at)?->diffForHumans() }}</span>
        </div>
        <div class="xw-progress" aria-hidden="true"><span data-live="bar" style="width: {{ max(3, $pct) }}%"></span></div>
        <p data-role="progress-text">
            <span data-live="discovered">{{ $latest->progress_discovered }}</span> address(es) found &middot;
            <span data-live="checked">{{ $latest->progress_checked }}</span> checked
            @if($budget > 0)<span class="xw-mut">(this check reads at most {{ $budget }} pages)</span>@endif
        </p>
        <p class="xw-mut" role="status" aria-live="polite" data-live="message">
            @if($report !== null)
                Showing results from {{ $report->finished_at?->diffForHumans() }}. A new check is running; this page updates by itself when it finishes.
            @else
                This page updates by itself when the check finishes. You do not need to refresh.
            @endif
        </p>
    </section>
@elseif($latest === null)
    <div class="xw-banner is-info" role="status" data-role="status-none">Your website has not been checked yet. Choose <strong>Start first audit</strong> to read your public pages and see what to improve.</div>
@elseif($failure !== null)
    <div class="xw-banner {{ $failure['fault'] === 'site' ? 'is-bad' : 'is-warn' }}" role="status" data-role="status-failed" data-fault="{{ $failure['fault'] }}">
        <div><strong>{{ $failure['title'] }}.</strong> {{ $failure['body'] }}</div>
        @if($report !== null)
            <div>Showing your previous results from {{ $report->finished_at?->diffForHumans() }} below.</div>
        @endif
        <div class="xw-mut">Checked {{ $latest->finished_at?->diffForHumans() }}.</div>
    </div>
@elseif($latest->status === 'cancelled' && $report !== null)
    <div class="xw-banner is-info" role="status" data-role="status-cancelled">The last check was cancelled. Showing your previous results from {{ $report->finished_at?->diffForHumans() }}.</div>
@elseif($latest->status === 'cancelled')
    <div class="xw-banner is-info" role="status" data-role="status-cancelled">The last check was cancelled. Start a new one when you are ready.</div>
@elseif($reportIsLatest && $report->status === 'partial')
    <div class="xw-banner is-warn" role="status" data-role="status-partial">
        <div><strong>Partially completed.</strong> {{ P::finishReason($report->finish_reason, $report->coverage) }}</div>
    </div>
@endif
