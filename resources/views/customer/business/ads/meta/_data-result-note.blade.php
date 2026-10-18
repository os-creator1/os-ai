{{--
    Meta Ads Module V1 contract 24 §5.2 — the one-line note under a results
    table: which result type the numbers count, or the hint to choose one.
    Reads $resultLabel (null = no type chosen) and the route identifiers.
--}}
@if($resultLabel === null)
    <p class="text-caption text-muted mb-1" data-role="result-type-unset">
        Results and cost per result are not shown yet: choose which Meta result you count in
        <a href="{{ route('customer.workspaces.businesses.ads.meta.settings', [$workspaceUid, $businessUid]) }}">Settings</a>.
    </p>
@else
    <p class="text-caption text-muted mb-1" data-role="result-type-note">
        Results count: {{ $resultLabel }}. The type is chosen in
        <a href="{{ route('customer.workspaces.businesses.ads.meta.settings', [$workspaceUid, $businessUid]) }}">Settings</a>.
    </p>
@endif
