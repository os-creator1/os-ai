{{--
    Budget pacing panel shared by Google Ads and Meta Ads overviews. Reads the
    existing GoogleAdsPacing value object (Meta reuses it): month-to-date spend
    against the Business's OWN monthly budget target (a planning figure in the
    ad account currency; never the SaaS billing cycle). Nothing is projected
    here; the projection is the reader's, and is hidden when it has none.

    Expects: $pacing, $money (micros => string), $projectedMicros,
    $projectionLowConfidence, $budgetUrl (?string), $canManageBudget (bool).
--}}
@php
    use App\Library\GoogleAds\Reporting\GoogleAdsPacingStatus as PacingStatus;

    $status = $pacing->status;
    $hasTarget = $pacing->monthlyTargetMicros !== null && $pacing->monthlyTargetMicros > 0;
    [$pillLabel, $pillClass] = match ($status) {
        PacingStatus::OnPace => ['On pace', 'is-success'],
        PacingStatus::Ahead => ['Ahead of pace', 'is-warning'],
        PacingStatus::Behind => ['Behind pace', 'is-info'],
        PacingStatus::NoTarget => ['No target', ''],
        PacingStatus::InsufficientData => ['Not enough data yet', ''],
    };
    $pacingText = match ($status) {
        PacingStatus::OnPace => 'On pace with your monthly budget',
        PacingStatus::Ahead => 'Spending is ahead of your monthly budget',
        PacingStatus::Behind => 'Spending is behind your monthly budget',
        PacingStatus::NoTarget => 'Set a monthly budget target in Settings to see pacing',
        PacingStatus::InsufficientData => 'A few more days of data are needed to judge pacing',
    };
    $spentShare = $hasTarget && $pacing->spentMicros !== null ? max(0, min(1, $pacing->spentMicros / $pacing->monthlyTargetMicros)) : null;
    $elapsed = $pacing->elapsedProportion !== null ? max(0, min(1, $pacing->elapsedProportion)) : null;
@endphp

<section class="cx-card" data-role="budget-pacing">
    <div class="cx-card-head is-plain">
        <h2 class="cx-card-title">Budget pacing</h2>
        <span class="cx-pill {{ $pillClass }}">{{ $pillLabel }}</span>
    </div>
    <div class="cx-card-body">
        <p class="text-caption mb-25">Spent this month</p>
        <p class="ads-pacing-figure" data-role="pacing-spent">{{ $money($pacing->spentMicros) }}</p>

        @if($hasTarget)
            <div class="ads-meter" role="img" aria-label="{{ $spentShare === null ? 'No spend yet' : number_format($spentShare * 100, 0) . '% of the monthly target spent' }}{{ $elapsed === null ? '' : ', ' . number_format($elapsed * 100, 0) . '% of the month gone' }}">
                <span style="width: {{ number_format(($spentShare ?? 0) * 100, 2, '.', '') }}%"></span>
                @if($elapsed !== null)
                    <i style="left: calc({{ number_format($elapsed * 100, 2, '.', '') }}% - 1px)" title="Today"></i>
                @endif
            </div>
            <div class="ads-meter-legend">
                <span>{{ $spentShare === null ? '—' : number_format($spentShare * 100, 0) . '% of target' }}</span>
                <span>Day {{ $pacing->daysElapsed }} of {{ $pacing->daysInMonth }}</span>
            </div>
        @endif

        <p class="text-caption mt-1 mb-0" data-role="pacing-status">
            {{ $pacingText }}@if($hasTarget) (target {{ $money($pacing->monthlyTargetMicros) }})@endif.
        </p>

        <div class="ads-pacing-box">
            <span class="d-block text-caption">Projected month-end spend</span>
            <strong>{{ $money($projectedMicros) }}</strong>
            <span class="d-block text-caption">
                @if($projectedMicros === null)
                    We need a few days of data to estimate this.
                @elseif($projectionLowConfidence)
                    Early estimate: based on only a few days of data, so it may change.
                @else
                    Based on this month's daily average so far.
                @endif
            </span>
        </div>

        @if(! $hasTarget)
            <p class="text-caption mb-1">No monthly budget target yet. Set one to see whether you're on pace.</p>
        @endif

        @if($budgetUrl)
            <a href="{{ $budgetUrl }}" class="btn btn-outline-primary btn-sm w-100 justify-content-center d-inline-flex" data-role="budget-target-link">
                @if($canManageBudget)
                    {{ $hasTarget ? 'Update budget target' : 'Set a budget target' }}
                @else
                    View budget settings
                @endif
            </a>
        @endif
    </div>
</section>
