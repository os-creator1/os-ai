{{--
    Meta Ads Module V1 — the status cell content of a campaign / ad set / ad
    row: Meta's effective status in owner words, a "Pending confirmation" badge
    when a pause/resume answer is outstanding, and Meta's own wording quoted
    when it reports a delivery issue. `$row` has status, effectiveStatus, uid;
    `$pending` is the uid => true map.
--}}
@php
    use App\Library\MetaAds\MetaAdsDisplay as D;

    $issue = D::issueText($row->effectiveStatus);
@endphp
<x-badge :variant="D::statusVariant($row->status, $row->effectiveStatus)" data-role="entity-status">{{ D::statusLabel($row->status, $row->effectiveStatus) }}</x-badge>
@if($pending[$row->uid] ?? false)
    <x-badge variant="warning" data-role="pending-confirmation">Pending confirmation</x-badge>
@endif
@if($issue !== null)
    <div class="text-caption text-muted" data-role="meta-issue">{{ $issue }}</div>
@endif
