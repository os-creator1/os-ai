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
    <div class="row mb-2 align-items-center">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ $website->name }}</h4>
            <x-badge :variant="$website->status->value === 'published' ? 'success' : ($website->status->value === 'archived' ? 'neutral' : 'warning')">
                {{ ucfirst($website->status->value) }}
            </x-badge>
        </div>
    </div>

    <nav class="website-tabs nav nav-pills mb-3" aria-label="Website">
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
@endsection

@section('page-script')
    @include('partials.section-router._script')
    @include('customer.business.website._brand-fields-script')
    @include('customer.business.website.studio._scripts')
    @include('customer.business.website._generation-progress')
@endsection
