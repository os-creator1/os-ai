{{--
    Website module shell: the name and status, the tab strip, and ONE swappable content region.
    The header and the tab strip never reload: a click on a tab swaps only #website-content (shared
    SectionRouter, `?fragment=1` answered by WebsiteStudioController::show itself). Every tab keeps its
    direct URL and works as an ordinary link without JavaScript.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', $tabLabels[$tab] ?? 'Website')

@section('page-style')
    @include('partials.section-router._styles')
    @include('customer.business.website.studio._styles')
@endsection

@section('content')
    @php
        $shellDesign = \App\Library\Website\Design\WebsiteDesigns::forTemplateKey($website->template_key);
        $shellStatus = $website->status->value;
        $settingsUrl = route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid, 'settings']);
        $templateUrl = route('customer.workspaces.businesses.website.rebuild.form', [$workspaceUid, $businessUid]);
    @endphp
    <div class="wb">
        <div class="wb-head">
            <div class="wb-head-text">
                <h1 class="wb-title">Website</h1>
                <div class="wb-subline">
                    <span class="wb-status {{ $shellStatus === 'published' ? 'is-published' : ($shellStatus === 'archived' ? '' : 'is-draft') }}">{{ ucfirst($shellStatus) }}</span>
                    <span>{{ $website->name }}@if ($shellDesign) &middot; {{ $shellDesign->label }} template @endif</span>
                </div>
            </div>
            <div class="wb-head-actions">
                {{-- The overview's Edit action: the same Settings tab link as before, now in the header (swaps the centre like a tab). --}}
                <a class="btn btn-outline-secondary" href="{{ $settingsUrl }}" data-testid="action-edit" data-website-go="settings"><x-ds-icon name="pencil" size="16" aria-hidden="true" /> Edit site</a>
                {{-- Shown on the Website tab only; the header never reloads on a tab swap, so the shared script toggles it (data-website-only). --}}
                <a class="btn btn-outline-secondary" href="{{ $templateUrl }}" data-testid="action-change-template" data-website-only="website" @if ($tab !== 'website') hidden @endif>Change template</a>
            </div>
        </div>

        <nav class="website-tabs nav" aria-label="Website">
            @foreach ($tabLabels as $tabKey => $tabLabel)
                <a class="nav-link @if($tab === $tabKey) active @endif"
                   href="{{ $tabKey === 'website' ? route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]) : route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid, $tabKey]) }}"
                   data-website-nav data-section-key="{{ $tabKey }}"
                   @if ($tab === $tabKey) aria-current="page" @endif>{{ $tabLabel }}</a>
            @endforeach
        </nav>

        <x-flash-alert class="mb-3" />

        @include('customer.business.website.studio._content')

        <div id="website-live-status" class="mg-sr-only" role="status" aria-live="polite"></div>
    </div>
@endsection

@section('page-script')
    @include('partials.section-router._script')
    @include('customer.business.website._brand-fields-script')
    @include('customer.business.website.studio._scripts')
    @include('customer.business.website._generation-progress')
@endsection
