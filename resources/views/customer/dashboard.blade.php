{{--
    Customer Experience Slice 4 — the customer home (user.home).

    Renders one App\Library\Dashboard\DashboardSnapshot, chosen by the
    resolved CustomerContext frame: Business Home, Agency Account Home, the
    Account-frame chooser, or the zero-Business state. Everything here was
    assembled by the presenters before this view ran — no query, no Auth
    lookup, no model call happens in these templates. No page stylesheet and
    no chart: detailed analysis lives in Results.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Dashboard'))

@section('content')
    {{-- The shared title bar (panels.breadcrumb) is switched off on this page
         so its <h2> never precedes the page's own <h1>; the View-as-client
         banner it normally carries is therefore rendered here, first, in
         every branch (§3, §15). The component renders nothing unless a
         view-as session is active. --}}
    <x-view-as-banner />

    <div class="customer-dashboard" data-role="dashboard" data-kind="{{ $dashboard->kind }}">
        <div class="mb-2" data-role="dashboard-header">
            <h1 class="text-page-title mb-0" id="dashboard-title">
                <span class="d-block text-caption text-uppercase text-muted" data-role="dashboard-frame">{{ $dashboard->frameLabel }}</span>
                <span data-role="dashboard-heading">{{ $dashboard->heading }}</span>
            </h1>
        </div>

        @switch($dashboard->kind)
            @case(\App\Library\Dashboard\DashboardSnapshot::KIND_BUSINESS)
                @include('customer.dashboard.business-home', ['dashboard' => $dashboard])
                @break
            @case(\App\Library\Dashboard\DashboardSnapshot::KIND_AGENCY)
                @include('customer.dashboard.agency-home', ['dashboard' => $dashboard])
                @break
            @case(\App\Library\Dashboard\DashboardSnapshot::KIND_CHOOSER)
                @include('customer.dashboard.chooser', ['dashboard' => $dashboard])
                @break
            @default
                @include('layouts.partials.empty-state', $dashboard->emptyState)
        @endswitch
    </div>
@endsection
