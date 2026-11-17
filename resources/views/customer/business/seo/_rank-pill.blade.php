{{--
    Search keywords — one rank cell as the design's pill. Same truth rules as
    _rank-badge: absence is NEVER a number ("Not in top N", "Not matched", "—"),
    a missing domain/phone is explained, and a result that is still coming is a
    skeleton (its words stay in the page for assistive tech). Escaped output only.

    @param \App\Models\SeoRankObservation|null $obs
    @param string $kind  organic|local
    @param string $state SeoRankDashboardReader::STATE_*
    @param string|null $blockedReason full reason a check can never run (shown as the title)
    @param string|null $blockedLabel  short label shown in the cell
--}}
@php
    use App\Enums\Seo\SeoRankObservationStatus;
    use App\Library\Seo\Rank\SeoRankDashboardReader;

    $isLocal = $kind === 'local';
    $blockedReason = $blockedReason ?? null;
    $blockedLabel = $blockedLabel ?? 'Needs domain';
@endphp
@if($obs === null)
    @if($state === SeoRankDashboardReader::STATE_PAUSED)
        <span class="kw-rk kw-rk-neutral" data-role="rank-state" data-state="paused">Paused</span>
    @elseif($blockedReason !== null && $state !== SeoRankDashboardReader::STATE_UNTRACKED)
        <span class="kw-mut kw-faint" data-role="rank-needs-identity" title="{{ $blockedReason }}">{{ $blockedLabel }}</span>
    @elseif($state === SeoRankDashboardReader::STATE_CHECKING)
        <span class="kw-sk" aria-hidden="true" data-role="rank-state" data-state="checking"></span><span class="visually-hidden">Checking…</span>
    @elseif($state === SeoRankDashboardReader::STATE_UNAVAILABLE)
        <span class="kw-rk kw-rk-neutral" data-role="rank-state" data-state="unavailable">Checks unavailable</span>
    @elseif($state === SeoRankDashboardReader::STATE_BUDGET_PAUSED)
        <span class="kw-rk kw-rk-amber" data-role="rank-state" data-state="budget-paused">Budget paused</span>
    @elseif($state === SeoRankDashboardReader::STATE_WAITING)
        <span class="kw-sk" aria-hidden="true" data-role="rank-state" data-state="waiting"></span><span class="visually-hidden">Waiting for first check</span>
    @else
        <span class="kw-mut" data-role="rank-empty">—</span>
    @endif
@elseif($obs->status === SeoRankObservationStatus::Found && $obs->position !== null)
    @php $tone = $obs->position <= 3 ? 'green' : ($obs->position <= 10 ? 'blue' : 'amber'); @endphp
    <span class="kw-rk kw-rk-{{ $tone }}" data-role="rank-value" data-position="{{ $obs->position }}">
        @if($isLocal)<svg class="kw-ic" viewBox="0 0 24 24" style="width:12px;height:12px" aria-hidden="true"><path d="M12 21s-7-6.200-7-11.500a7 7 0 0 1 14 0C19 14.800 12 21 12 21z"></path><circle cx="12" cy="9.500" r="2.500"></circle></svg><span class="visually-hidden">Local </span>@endif#{{ $obs->position }}
    </span>
@elseif($obs->status === SeoRankObservationStatus::NotMatched)
    <span class="kw-rk kw-rk-neutral" data-role="rank-not-matched">Not matched</span>
@else
    <span class="kw-rk kw-rk-neutral" data-role="rank-not-found">{{ $isLocal ? 'Not in local results' : 'Not in top ' . $obs->depth_checked }}</span>
@endif
