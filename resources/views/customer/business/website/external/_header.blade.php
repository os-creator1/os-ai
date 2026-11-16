{{--
    Page header: title, source tag, the owner's own address, when the newest finished audit ran, the one primary action,
    and the tab bar. Variables: $latest, $report, $websiteUrl, $tabs, $active (tab key), $crawlUrl, $cancelUrl.
    Rendered inside #external-live so a finishing check refreshes it in place.
--}}
@php
    use App\Library\ExternalSite\ExternalAuditPresenter as P;

    $hdrReport = $report ?? null;
    $hdrRunning = $latest !== null && $latest->isActive();
    $hdrReportIsLatest = $hdrReport !== null && $latest !== null && $hdrReport->id === $latest->id;
    $hdrTone = fn (string $variant): string => ['success' => 'xw-t-ok', 'warning' => 'xw-t-am', 'danger' => 'xw-t-bad', 'accent' => 'xw-t-new'][$variant] ?? 'xw-t-low';
@endphp

<div class="xw-head" data-role="status-bar">
    <div class="xw-head-text">
        <h1 class="xw-title">Website</h1>
        <div class="xw-meta">
            <span class="xw-tg xw-tg-lg xw-t-low" data-role="website-source">External website</span>
            @if($websiteUrl !== '')
                <a class="xw-lk" href="{{ preg_match('#^https?://#i', $websiteUrl) ? $websiteUrl : 'https://'.$websiteUrl }}" target="_blank" rel="noopener noreferrer" data-role="website-url">{{ preg_replace('#^https?://#', '', rtrim($websiteUrl, '/')) }}<svg class="xi" viewBox="0 0 24 24" style="width:13px;height:13px"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg></a>
            @endif
            <span data-role="last-checked">
                @if($hdrReport?->finished_at)
                    &middot; <span title="Latest completed audit: {{ $hdrReport->finished_at->toDayDateTimeString() }}">Checked {{ $hdrReport->finished_at->diffForHumans() }}</span>
                    @if($hdrReport->status !== 'completed')<span class="xw-tg {{ $hdrTone(P::statusVariant($hdrReport)) }}" data-role="report-status">{{ P::statusLabel($hdrReport) }}</span>@endif
                @else
                    &middot; No completed audit yet
                @endif
                @if($latest !== null && ($hdrRunning || ! $hdrReportIsLatest))
                    &middot; Current check: <span class="xw-tg {{ $hdrRunning ? 'xw-t-new' : $hdrTone(P::statusVariant($latest)) }}" data-role="current-status">{{ P::statusLabel($latest) }}</span>
                @endif
            </span>
        </div>
    </div>
    <div class="xw-head-actions">
        @if($hdrRunning)
            <form method="POST" action="{{ $cancelUrl }}" data-live-post>@csrf<button type="submit" class="xw-btn xw-btn-line" data-role="cancel-check">Cancel check</button></form>
        @else
            <form method="POST" action="{{ $crawlUrl }}" data-live-post>@csrf<button type="submit" class="xw-btn xw-btn-primary" data-role="check-again"><svg class="xi" viewBox="0 0 24 24"><path d="M20 11a8 8 0 0 0-14.500-4.500L4 8M4 4v4h4M4 13a8 8 0 0 0 14.500 4.500L20 16M20 20v-4h-4"/></svg>{{ $hdrReport !== null || $latest !== null ? 'Check again now' : 'Start first audit' }}</button></form>
        @endif
    </div>
</div>

<nav class="xw-tabs" aria-label="Website" data-role="external-nav">
    @foreach($tabs as $tab)
        <a class="xw-tab @if($active === $tab['key']) is-active @endif" href="{{ $tab['url'] }}" data-nav="{{ $tab['key'] }}" @if($active === $tab['key']) aria-current="page" @endif>{{ $tab['label'] }}</a>
    @endforeach
</nav>
