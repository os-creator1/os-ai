{{--
    The one stable shell for SEO -> Content (Autopilot, Articles, Content Plan, Opportunities). The Business OS shell, the
    "Content" title, its subtitle / action and the tab strip live OUTSIDE #seo-content-region and are never replaced;
    #seo-content-region is the only thing the shared SectionRouter swaps (the same ?fragment=1 contract as Calendar and
    Website - see partials/section-router/_script). Every tab is still a real link to its own server-rendered URL.

    A section view `@extends` this frame (or `_fragment` when the router asks for just its region) and supplies:
      content-active    'autopilot' | 'articles' | 'plan' | 'opportunities'
      content-subtitle  the one line under the title
      content-action    (optional) a header action such as "Write an article"
      content-section   the tab's own markup
    plus `title`. A section view must not define its own page-script / page-style: it would replace this frame's and the tab
    router would never load.

    Expects: $workspaceUid, $businessUid, $withOpportunities (bool, Articles only; every other section is module-only so it defaults true) (the Autopilot / Content Plan / Opportunities are part of SeoModule).
--}}
@extends('layouts/contentLayoutMaster')

@section('page-style')
    @include('partials.section-router._styles')
    @include('customer.business.seo.content._styles')
@endsection

@section('content')
    <div class="content-module-header" data-role="content-header">
        <div class="content-module-heading">
            <h4 class="mb-25">Content</h4>
            <p class="text-caption mb-0" id="content-subtitle">{{ trim($__env->yieldContent('content-subtitle')) }}</p>
        </div>
        <div class="content-module-action" id="content-header-action">@yield('content-action')</div>
    </div>

    @include('customer.business.seo.content._nav', ['active' => trim($__env->yieldContent('content-active')), 'withModule' => $withModule ?? ($withOpportunities ?? true)])

    <x-flash-alert class="mb-2" />

    <div id="seo-content-region"
         data-content-section="{{ trim($__env->yieldContent('content-active')) }}"
         data-content-title="{{ trim($__env->yieldContent('title')) }}"
         data-content-subtitle="{{ trim($__env->yieldContent('content-subtitle')) }}">
        @yield('content-section')
    </div>

    <div id="content-live-status" class="mg-sr-only" role="status" aria-live="polite"></div>
@endsection

@section('page-script')
    @include('partials.section-router._script')
    @include('customer.business.seo.content._scripts')
@endsection
