@extends('layouts/contentLayoutMaster')

{{-- White Label → Analytics: the funnel of the Agency's own published landing page (read-only). --}}
@section('title', 'White label analytics')

@section('content')
    <section id="agency-white-label">
        <h2 class="mb-2">White label</h2>
        @include('customer.agency.white-label._tabs')

        @if (! $entitled)
            <x-card title="Not included in your plan" class="mb-2" data-role="white-label-not-entitled">
                <p class="mb-0">White label is not included in your current plan.</p>
            </x-card>
        @else
            <p class="text-caption mb-3" data-role="analytics-intro">
                How many people visited <strong>{{ $landingLabel ?? 'your landing page' }}</strong>, when, how many opened your calendar, and how many booked.
                @if ($landingUrl) <a href="{{ $landingUrl }}" target="_blank" rel="noopener">{{ $landingUrl }}</a>@endif
            </p>
            @include('customer.website-analytics._report')
        @endif
    </section>
@endsection
