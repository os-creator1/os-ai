{{--
    Contract 18, Sub-slice 18A — the Business-scoped SEO Overview.

    READ-ONLY. There is no form, no write control and no provider call on
    this page. It shows plain facts drawn from platform-owned data: there is
    no SEO score, grade, percentage, chart or AI-generated analysis, and no
    tab or section for anything that is not built yet (no Keywords, Search
    Console, Citations, Reviews or audit — not even disabled).

    Every value is rendered with escaped Blade output only. Raw, unescaped
    output is forbidden in this view. The only customer-supplied strings are
    Location names and the Business name; readiness copy is fixed registry
    text.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'SEO')

@php
    use App\Enums\Seo\SeoReadinessState;
    use App\Library\Seo\SeoReadinessItem;

    $stateWord = [
        SeoReadinessState::Met->value => 'Done',
        SeoReadinessState::NotMet->value => 'To do',
        SeoReadinessState::NotApplicable->value => 'Not applicable',
    ];
@endphp

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">SEO</h4>
            <span class="text-caption">{{ $business->name }}</span>
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    <x-card :padded="true" class="mb-2" data-section="readiness">
        <p class="text-section-heading mb-1">Search visibility basics</p>
        <ul class="list-unstyled mb-0">
            @foreach($overview->readiness as $item)
                <li class="py-50 @unless($loop->last) border-bottom @endunless" data-role="readiness-item" data-key="{{ $item->key }}" data-state="{{ $item->state->value }}">
                    <span class="badge bg-light text-dark me-1" data-role="readiness-state">{{ $stateWord[$item->state->value] }}</span>
                    <span>{{ $item->label }}</span>
                    @if($item->detail !== null)
                        <span class="text-caption d-block">{{ $item->detail }}</span>
                    @endif
                    @if($item->fix === SeoReadinessItem::FIX_BUSINESS_SETTINGS)
                        @can('access_backend')
                            <a class="text-caption" href="{{ route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]) }}" data-role="readiness-fix">Open Business settings</a>
                        @endcan
                    @elseif($item->fix === SeoReadinessItem::FIX_SEO_KEYWORDS)
                        <a class="text-caption" href="{{ route('customer.workspaces.businesses.seo.keywords.index', [$workspaceUid, $businessUid]) }}" data-role="readiness-fix">Add search keywords</a>
                    @elseif($item->fix === SeoReadinessItem::FIX_WEBSITE)
                        @can('website')
                            <a class="text-caption" href="{{ route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]) }}" data-role="readiness-fix">Open your website</a>
                        @endcan
                    @endif
                </li>
            @endforeach
        </ul>
    </x-card>

    <x-card :padded="true" class="mb-2" data-section="published-website">
        <p class="text-section-heading mb-1">Your published website</p>
        @if($overview->content === null)
            <p class="mb-0" data-role="no-published-website">You have no published website yet.</p>
        @else
            <ul class="list-unstyled mb-0">
                <li data-role="content-pages">{{ $overview->content['pages'] }} {{ $overview->content['pages'] === 1 ? 'page' : 'pages' }} published</li>
                <li data-role="content-meta">{{ $overview->content['with_meta_description'] }} of {{ $overview->content['pages'] }} with a meta description</li>
                <li data-role="content-title">{{ $overview->content['with_seo_title'] }} of {{ $overview->content['pages'] }} with a search title</li>
                <li data-role="content-noindex">{{ $overview->content['marked_noindex'] }} marked to be hidden from search engines</li>
            </ul>
        @endif
    </x-card>

    <x-card :padded="true" class="mb-2" data-section="indexability">
        <p class="text-section-heading mb-1">Search engine indexing</p>
        <p class="mb-1" data-role="indexability-label" data-state="{{ $overview->indexability->value }}">{{ $overview->indexability->label() }}</p>
        <p class="text-caption mb-0">{{ $overview->indexability->detail() }}</p>
    </x-card>

    @if($overview->google !== null)
        <x-card :padded="true" class="mb-2" data-section="google-business-profile">
            <p class="text-section-heading mb-1">Google Business Profile</p>
            @if(count($overview->google) === 0)
                <p class="mb-0" data-role="google-none">No locations to show.</p>
            @else
                <ul class="list-unstyled mb-1">
                    @foreach($overview->google as $status)
                        <li class="py-50 @unless($loop->last) border-bottom @endunless" data-role="google-location" data-bound="{{ $status->bound ? '1' : '0' }}">
                            <strong>{{ $status->locationName }}</strong>
                            <span class="d-block" data-role="google-state">
                                @if(! $status->bound)
                                    Not linked to a Google listing yet
                                @elseif($status->connectionState === 'revoked')
                                    Google connection lost. Reconnect to keep this listing up to date.
                                @else
                                    Linked{{ $status->health !== null ? ' · ' . $status->health->label() : '' }}
                                @endif
                            </span>
                            @if($status->napMismatchCount !== null && $status->napMismatchCount > 0)
                                <span class="text-caption d-block" data-role="google-mismatch">{{ $status->napMismatchCount }} {{ $status->napMismatchCount === 1 ? 'detail differs' : 'details differ' }} from your Google listing</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]) }}" data-role="google-open">Open Google Business Profile</a>
            @endif
        </x-card>
    @endif
@endsection
