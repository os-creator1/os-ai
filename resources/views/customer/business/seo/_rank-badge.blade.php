{{--
    SEO Keyword Rank Tracking V1 — one organic/local rank cell.

    Absence is NEVER rendered as a number: not-found is "Not in top N", an
    unidentifiable listing is "Not matched", and no data is "—". Escaped output only.

    @param \App\Models\SeoRankObservation|null $obs
    @param string $kind  organic|local
    @param string $state SeoRankDashboardReader::STATE_*
--}}
@php
    use App\Enums\Seo\SeoRankObservationStatus;
    use App\Library\Seo\Rank\SeoRankDashboardReader;

    $isLocal = $kind === 'local';
@endphp
@if($obs === null)
    @if($state === SeoRankDashboardReader::STATE_CHECKING)
        <x-badge variant="accent" data-role="rank-state" data-state="checking">Checking…</x-badge>
    @elseif($state === SeoRankDashboardReader::STATE_UNAVAILABLE)
        <x-badge variant="neutral" data-role="rank-state" data-state="unavailable">Checks unavailable</x-badge>
    @elseif($state === SeoRankDashboardReader::STATE_BUDGET_PAUSED)
        <x-badge variant="warning" data-role="rank-state" data-state="budget-paused">Budget paused</x-badge>
    @elseif($state === SeoRankDashboardReader::STATE_PAUSED)
        <x-badge variant="neutral" data-role="rank-state" data-state="paused">Paused</x-badge>
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
