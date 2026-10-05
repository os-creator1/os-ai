{{--
    SEO V1 final — "can search engines find my website?", ONE partial for the
    SEO Overview and the Website check so the two can never disagree.

    A STATUS about how the site is set up, never a finding and never a ranking.
    The words and the page counts come from App\Library\Seo\SeoIndexability
    (computed from the PUBLISHED snapshot and the Website's Active primary
    domain); the plain-word badge is Good / Needs attention / Action. The fix is
    a link to the existing Website screen, shown only to someone who may open it.

    @param \App\Library\Seo\SeoIndexability $indexability
    @param string $workspaceUid
    @param string $businessUid
--}}
@php
    use App\Library\Seo\SeoIndexability;

    $toneVariant = match ($indexability->tone()) {
        SeoIndexability::TONE_GOOD => 'success',
        SeoIndexability::TONE_ATTENTION => 'warning',
        default => 'danger',
    };

    $actionUrl = match ($indexability->action()) {
        SeoIndexability::ACTION_PUBLISH => route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]),
        SeoIndexability::ACTION_CONNECT_DOMAIN => route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid]),
        SeoIndexability::ACTION_ALLOW_INDEXING => route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid]),
        default => null,
    };
@endphp
<x-card :padded="true" class="mb-2" data-section="indexability">
    <p class="text-section-heading mb-1">Can search engines find your website?</p>
    <p class="mb-50">
        <x-badge :variant="$toneVariant" data-role="indexability-tone">{{ $indexability->toneWord() }}</x-badge>
        <strong class="ms-50" data-role="indexability-label" data-state="{{ $indexability->state->value }}">{{ $indexability->label() }}</strong>
    </p>
    <p class="text-caption mb-0" data-role="indexability-detail">{{ $indexability->detail() }}</p>
    @if($actionUrl !== null)
        @can('website')
            <a class="d-inline-block mt-50" href="{{ $actionUrl }}" data-role="indexability-action" data-action="{{ $indexability->action() }}">{{ $indexability->actionLabel() }}</a>
        @endcan
    @endif
</x-card>
