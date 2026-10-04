{{--
    Google Ads Module V1 (contract 23 §5/§16) — the freshness line shown on
    EVERY data page: "Updated {relative}" + "Data through {date}", and a calm
    (never red) warning when the latest refresh failed or the data is stale —
    the last successful figures are still shown. Included by _header; takes a
    GoogleAdsFreshnessSnapshot as $freshness.
--}}
@php
    use App\Library\GoogleAds\Sync\GoogleAdsFreshnessState;

    $lastSync = $freshness->lastSuccessfulSyncAt;
    $through = $freshness->dataThroughDate;
@endphp

<div class="mb-2" data-role="ads-freshness" data-state="{{ $freshness->state->value }}">
    <p class="text-caption text-muted mb-0">
        @if($freshness->state === GoogleAdsFreshnessState::Running)
            <span data-role="freshness-running">Refreshing now.</span>
        @endif
        @if($lastSync !== null)
            <span data-role="freshness-updated">Updated {{ $lastSync->diffForHumans() }}</span>
            @if($through !== null)
                <span aria-hidden="true">&middot;</span>
                <span data-role="freshness-through">Data through {{ $through->format('M j, Y') }}</span>
            @endif
        @elseif($freshness->state !== GoogleAdsFreshnessState::Running)
            <span data-role="freshness-never">Waiting for the first update from Google Ads.</span>
        @endif
    </p>

    @if($freshness->shouldWarn())
        <x-alert variant="warning" icon="alert-triangle" role="status" class="mt-1 mb-0" data-role="freshness-warning">
            Showing your last successful update.
            @if($freshness->lastFailureLabel)
                Latest refresh failed: {{ $freshness->lastFailureLabel }}
            @else
                The latest refresh is overdue and will be retried automatically.
            @endif
        </x-alert>
    @endif
</div>
