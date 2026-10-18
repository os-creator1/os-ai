@extends('layouts/contentLayoutMaster')

@section('title', 'Add booking type')

@section('page-style')
    @include('customer.business.calendar._styles')
@endsection

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid, $location->uid];
        $locationName = $location->name ?: 'this location';
        // Server-rendered starting values of the live summary (the script keeps it in step with the inputs).
        $duration = (int) old('duration_minutes', 30);
        $interval = (int) old('slot_interval_minutes', 30);
        $windowDays = (int) old('booking_window_days', 30);
        $notice = (int) old('minimum_notice_minutes', 0);
        $noticeLabel = $notice % 60 === 0 ? ($notice / 60).' hour'.($notice === 60 ? '' : 's') : $notice.' minutes';
    @endphp

    <a href="{{ route('customer.workspaces.businesses.calendar.booking-types.index', $scope) }}" class="cx-back">
        <x-ds-icon name="arrow-left" size="14" aria-hidden="true" />
        Back to booking types
    </a>

    <header class="cx-page-head">
        <div>
            <h1 class="cx-page-title">New booking type</h1>
            <p class="cx-page-sub">This booking type will belong to <strong>{{ $locationName }}</strong> only.</p>
        </div>
    </header>

    @if ($errors->any())
        <div class="cx-callout is-danger mb-2" role="alert">
            <x-ds-icon name="alert-circle" size="16" aria-hidden="true" />
            <span class="cx-callout-text">Some details need attention. Check the highlighted fields below.</span>
        </div>
    @endif

    <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.booking-types.store', $scope) }}" class="cx-split">
        @csrf

        <div class="cx-split-main">
            @include('customer.business.calendar.booking-types._settings-fields', ['bookingType' => null])
        </div>

        <aside class="cx-split-aside is-sticky">
            <section class="cx-card" data-bt-summary aria-labelledby="bt-summary-title">
                <div class="cx-card-head is-plain"><h2 class="cx-card-title" id="bt-summary-title">What customers will get</h2></div>
                <div class="cx-card-body">
                    <ul class="bt-summary" aria-live="polite">
                        <li><x-ds-icon name="clock" size="15" aria-hidden="true" /><span data-bt-summary-item="duration">{{ $duration }} minute appointment</span></li>
                        <li><x-ds-icon name="plus" size="15" aria-hidden="true" /><span data-bt-summary-item="interval">Start times every {{ $interval === 60 ? 'hour' : $interval.' minutes' }}</span></li>
                        <li><x-ds-icon name="calendar" size="15" aria-hidden="true" /><span data-bt-summary-item="window">Bookable up to {{ $windowDays }} days ahead, {{ $notice > 0 ? 'with at least '.$noticeLabel.' notice' : 'with no minimum notice' }}</span></li>
                        <li><x-ds-icon name="map-pin" size="15" aria-hidden="true" /><span>At {{ $locationName }}</span></li>
                    </ul>
                    <div class="bt-aside-actions">
                        <x-button type="submit" variant="primary" icon="check">Create booking type</x-button>
                        <x-button variant="secondary" :href="route('customer.workspaces.businesses.calendar.booking-types.index', $scope)">Cancel</x-button>
                    </div>
                    <p class="text-caption mt-1 mb-0">Next you'll choose who offers it and set customer notifications.</p>
                </div>
            </section>
        </aside>
    </form>
@endsection

@section('page-script')
    @include('customer.business.calendar.booking-types._editor-script')
@endsection
