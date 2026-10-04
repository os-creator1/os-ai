{{--
    Meta Ads Module V1 (contract 24 §5.2, M11) — shown instead of a results
    figure when the owner has not chosen what counts as a "result". Results are
    UNAVAILABLE (not zero) until one type is chosen in Settings.
--}}
<x-card :padded="true" class="mb-2" data-role="result-type-prompt">
    <h2 class="text-section-heading mb-1">Choose a result type</h2>
    <p class="mb-1">Meta reports many kinds of actions, so we do not guess which one counts as a result for you. Choose one in Settings (for example leads from website forms) and we will show results and cost per result.</p>
    <a href="{{ route('customer.workspaces.businesses.ads.meta.settings', [$workspaceUid, $businessUid]) }}" data-role="choose-result-type">Choose a result type</a>
</x-card>
