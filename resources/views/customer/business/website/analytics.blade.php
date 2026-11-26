@extends('layouts/contentLayoutMaster')

@section('title', 'Analytics')

@section('page-style')
    @include('customer.business.website._manage-styles')
@endsection

@section('content')
    @include('customer.business.website._manage-header', ['active' => 'analytics'])

    <p class="text-muted mb-3" data-testid="analytics-intro">Visitors to your published website, and how many turned into a {{ $hasBooking && $hasForm ? 'booking or inquiry' : ($hasBooking ? 'booking' : 'inquiry') }}. Measured by MotionGrove — no Google Analytics account needed. Previews are never counted.</p>

    @include('customer.website-analytics._report')
@endsection
