{{--
    Meta Ads Module V1 contract 24 §7 — the pause / resume confirmation
    dialogs of a data table. One dialog per row that can change; each states
    exactly what changes, then POSTs to the mutation route (CSRF) carrying only
    the return hints (`from`, `q`, `return_campaign`), never a URL.

        @include('customer.business.ads.meta._data-actions', [
            'rows' => $result->items,
            'kind' => 'campaign',          // campaign | ad_set | ad
            'from' => 'campaigns',         // campaigns | campaign | ad-sets | ads
            'returnQuery' => $returnQuery,
            'returnCampaign' => null,
        ])

    Names are customer data and are escaped. The first click disables the
    submit button (see ads/_once-script); the mutation service dedupes anyway.
--}}
@php
    use App\Library\MetaAds\MetaAdsDisplay as D;

    $noun = ['campaign' => 'campaign', 'ad_set' => 'ad set', 'ad' => 'ad'][$kind];
    $plural = ['campaign' => 'campaigns', 'ad_set' => 'ad-sets', 'ad' => 'ads'][$kind];
    $routePrefix = 'customer.workspaces.businesses.ads.meta.' . $plural . '.';
@endphp
@foreach($rows as $row)
    @php $action = D::actionFor($row->status, $row->effectiveStatus, $kind === 'ad'); @endphp
    @if($action !== null)
        @php
            $pausing = $action === 'pause';
            $consequence = match (true) {
                $kind === 'campaign' && $pausing => 'It and its ad sets stop delivering until resumed.',
                $kind === 'campaign' => 'It will start delivering again. Ad sets and ads you paused on their own stay paused.',
                $kind === 'ad_set' && $pausing => 'It and its ads stop delivering until resumed.',
                $kind === 'ad_set' => 'It will start delivering again, unless its campaign is paused.',
                $pausing => 'It stops delivering until resumed.',
                default => 'It will start delivering again, unless its ad set or campaign is paused.',
            };
        @endphp
        @include('customer.business.ads._confirm-dialog', [
            'dialogId' => 'ads-confirm-' . $row->uid,
            'action' => route($routePrefix . $action, [$workspaceUid, $businessUid, $row->uid]),
            'title' => ($pausing ? 'Pause ' : 'Resume ') . $noun,
            'question' => ($pausing ? 'Pause ' : 'Resume ') . $noun . ' ' . $row->name . '?',
            'consequence' => $consequence,
            'confirmLabel' => ($pausing ? 'Pause ' : 'Resume ') . $noun,
            'from' => $from,
            'returnQuery' => $returnQuery,
            'returnCampaign' => $returnCampaign ?? null,
        ])
    @endif
@endforeach
