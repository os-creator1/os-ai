{{--
    Meta Ads Module V1 — the Pause / Resume button of one table row (opens the
    confirmation dialog rendered by _data-actions). `$row` has uid, status,
    effectiveStatus; `$kind` is campaign | ad_set | ad.
--}}
@php
    $cellAction = \App\Library\MetaAds\MetaAdsDisplay::actionFor($row->status, $row->effectiveStatus, $kind === 'ad');
@endphp
<td class="text-nowrap">
    @if($cellAction !== null)
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ads-confirm-{{ $row->uid }}" data-role="{{ $cellAction }}-{{ str_replace('_', '-', $kind) }}">{{ ucfirst($cellAction) }}</button>
    @else
        <span class="text-caption text-muted">—</span>
    @endif
</td>
