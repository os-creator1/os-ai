{{--
    The state of the check, in the owner's words, on every tab. Variables: $latest (newest crawl of the CURRENT
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

{{-- ---- header bar: latest report time, current status, the one primary action ---- --}}
<div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2" data-role="status-bar">
    <div class="text-caption text-muted" data-role="last-checked">
        @if($report?->finished_at)
            Latest completed audit: <span title="{{ $report->finished_at->toDayDateTimeString() }}">{{ $report->finished_at->diffForHumans() }}</span>
            <x-badge :variant="P::statusVariant($report)" data-role="report-status">{{ P::statusLabel($report) }}</x-badge>
        @else
            No completed audit yet
        @endif
        @if($latest !== null && ($active || ! $reportIsLatest))
            <span class="ms-1">&middot; Current check: <x-badge :variant="$active ? 'accent' : P::statusVariant($latest)" data-role="current-status">{{ P::statusLabel($latest) }}</x-badge></span>
        @endif
    </div>
    <div class="d-flex gap-1 align-items-center">
        <a class="btn btn-sm btn-flat-secondary" href="{{ $settingsUrl }}" data-role="settings-link">Settings</a>
        @if($active)
            <form method="POST" action="{{ $cancelUrl }}" data-live-post>@csrf<button type="submit" class="btn btn-sm btn-outline-secondary" data-role="cancel-check">Cancel check</button></form>
        @else
            <form method="POST" action="{{ $crawlUrl }}" data-live-post>@csrf<button type="submit" class="btn btn-sm btn-primary" data-role="check-again">{{ $report !== null || $latest !== null ? 'Check again now' : 'Start first audit' }}</button></form>
        @endif
    </div>
</div>

@if($active)
    @php
        $pct = $budget > 0 ? min(100, (int) round(($latest->progress_checked / max(1, $budget)) * 100)) : 0;
    @endphp
    <x-card :padded="true" class="mb-2" data-role="status-running" data-live-status data-active="1">
        <div class="d-flex align-items-center gap-1 mb-1">
            <strong data-live="stage">{{ P::stageLabel($latest->stage) }}</strong>
            <span class="text-caption text-muted">&middot; started {{ ($latest->started_at ?? $latest->created_at)?->diffForHumans() }}</span>
        </div>
        <div class="progress mb-1" style="height: 6px;" aria-hidden="true"><div class="progress-bar" data-live="bar" style="width: {{ max(3, $pct) }}%"></div></div>
        <p class="mb-1" data-role="progress-text">
            <span data-live="discovered">{{ $latest->progress_discovered }}</span> address(es) found &middot;
            <span data-live="checked">{{ $latest->progress_checked }}</span> checked
            @if($budget > 0)<span class="text-muted">(this check reads at most {{ $budget }} pages)</span>@endif
        </p>
        <p class="text-caption text-muted mb-0" role="status" aria-live="polite" data-live="message">
            @if($report !== null)
                Showing results from {{ $report->finished_at?->diffForHumans() }}. A new check is running; this page updates by itself when it finishes.
            @else
                This page updates by itself when the check finishes. You do not need to refresh.
            @endif
        </p>
    </x-card>
@elseif($latest === null)
    <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="status-none">Your website has not been checked yet. Choose <strong>Start first audit</strong> to read your public pages and see what to improve.</x-alert>
@elseif($failure !== null)
    <x-alert :variant="$failure['fault'] === 'site' ? 'danger' : 'warning'" icon="alert-triangle" role="status" class="mb-2" data-role="status-failed" data-fault="{{ $failure['fault'] }}">
        <strong>{{ $failure['title'] }}.</strong> {{ $failure['body'] }}
        @if($report !== null)
            <div class="mt-50">Showing your previous results from {{ $report->finished_at?->diffForHumans() }} below.</div>
        @endif
        <div class="text-caption mt-50">Checked {{ $latest->finished_at?->diffForHumans() }}.</div>
    </x-alert>
@elseif($latest->status === 'cancelled' && $report !== null)
    <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="status-cancelled">The last check was cancelled. Showing your previous results from {{ $report->finished_at?->diffForHumans() }}.</x-alert>
@elseif($latest->status === 'cancelled')
    <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="status-cancelled">The last check was cancelled. Start a new one when you are ready.</x-alert>
@elseif($reportIsLatest && $report->status === 'partial')
    <x-alert variant="warning" icon="alert-triangle" role="status" class="mb-2" data-role="status-partial">
        <strong>Partially completed.</strong> {{ P::finishReason($report->finish_reason, $report->coverage) }}
    </x-alert>
@endif
