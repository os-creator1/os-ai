{{--
    SEO Keyword Rank Tracking V1 — one organic/local rank cell.

    Absence is NEVER rendered as a number: not-found is "Not in top N", an
    unidentifiable listing is "Not matched", and no data is "—". When a tracked
    keyword has no result AND a check can never run (no domain / phone to match
    against) the cell says why instead of "Waiting for first check" forever.
    Escaped output only.

    @param \App\Models\SeoRankObservation|null $obs
    @param string $kind  organic|local
    @param string $state SeoRankDashboardReader::STATE_*
    @param string|null $blockedReason  why no check can run for this cell, or null
--}}
@php
    use App\Enums\Seo\SeoRankObservationStatus;
    use App\Library\Seo\Rank\SeoRankDashboardReader;

    $isLocal = $kind === 'local';
    $blockedReason = $blockedReason ?? null;
@endphp
@if($obs === null)
    {{-- A stopped target is just "Paused". Otherwise, when this cell can NEVER be checked (nothing to match
         against), say why before anything that implies a check is coming — an open run may exist for the
         OTHER check type. --}}
    @if($state === SeoRankDashboardReader::STATE_PAUSED)
        <x-badge variant="neutral" data-role="rank-state" data-state="paused">Paused</x-badge>
    @elseif($blockedReason !== null && $state !== SeoRankDashboardReader::STATE_UNTRACKED)
        <span class="text-caption" data-role="rank-needs-identity">{{ $blockedReason }}</span>
    @elseif($state === SeoRankDashboardReader::STATE_CHECKING)
        <x-badge variant="accent" data-role="rank-state" data-state="checking">Checking…</x-badge>
    @elseif($state === SeoRankDashboardReader::STATE_UNAVAILABLE)
        <x-badge variant="neutral" data-role="rank-state" data-state="unavailable">Checks unavailable</x-badge>
    @elseif($state === SeoRankDashboardReader::STATE_BUDGET_PAUSED)
        <x-badge variant="warning" data-role="rank-state" data-state="budget-paused">Budget paused</x-badge>
    @elseif($state === SeoRankDashboardReader::STATE_WAITING)
        <x-badge variant="neutral" data-role="rank-state" data-state="waiting">Waiting for first check</x-badge>
    @else
        <span class="text-muted" data-role="rank-empty">—</span>
    @endif
@elseif($obs->status === SeoRankObservationStatus::Found && $obs->position !== null)
    @php $variant = $obs->position <= 3 ? 'success' : ($obs->position <= 10 ? 'accent' : 'neutral'); @endphp
    <x-badge :variant="$variant" data-role="rank-value" data-position="{{ $obs->position }}">{{ $isLocal ? 'Local ' : '' }}#{{ $obs->position }}</x-badge>
@elseif($obs->status === SeoRankObservationStatus::NotMatched)
    <x-badge variant="neutral" data-role="rank-not-matched">Not matched</x-badge>
@else
    <x-badge variant="neutral" data-role="rank-not-found">{{ $isLocal ? 'Not in local results' : 'Not in top ' . $obs->depth_checked }}</x-badge>
@endif
