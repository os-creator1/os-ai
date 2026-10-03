{{--
    Where this website's reviews can come from. Each source is its own
    entry so a future canonical Google Business Profile Reviews read seam
    can plug in without reshaping this component. Nothing here displays a
    rating, a review count or review text: those do not exist behind the
    boundary today and are never invented.
--}}
@php
    $gbp = collect($reviewSource['sources'])->firstWhere('key', \App\Library\Website\Setup\WebsiteReviewSourceStatus::SOURCE_GBP);
@endphp
<div class="card card-body mt-3 bg-light" data-review-sources>
    <h6 class="mb-1">Where your reviews come from</h6>
    <p class="text-caption mb-2">Add reviews you've confirmed yourself above. We only show reviews you've approved — nothing is invented.</p>

    @if ($gbp && $gbp['state'] === 'connected')
        <p class="mb-0" data-gbp-state="connected">
            <strong>Google Business Profile is connected.</strong>
            <span class="text-caption">Importing Google reviews into your website isn't available yet; when it is, you'll be able to choose which reviews to show here.</span>
        </p>
    @elseif ($gbp && $gbp['state'] === 'not_connected')
        <p class="mb-0" data-gbp-state="not_connected">
            Optional: connect Google Business Profile.
            <a href="{{ route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]) }}">Connect Google Business Profile</a>
            <span class="text-caption d-block">You can skip this — your website doesn't need it. Importing Google reviews isn't available yet.</span>
        </p>
    @endif
</div>
