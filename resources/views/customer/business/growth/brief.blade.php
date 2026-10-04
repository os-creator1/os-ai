{{-- Growth Center — the Daily Brief as its own page. In-app only: nothing is sent. --}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Growth Center — Daily Brief')

@section('page-style')
    @include('customer.business.growth._styles')
@endsection

@section('content')
    @include('customer.business.growth._header', ['tab' => 'insights'])

    <div class="gc">
        <section class="gc-panel" style="max-width:44rem" data-role="daily-brief">
            <h2 class="gc-section-title">Your brief for {{ now()->format('l, F j') }}</h2>
            <p class="gc-section-sub mb-1">{{ $business->name }}</p>
            @include('customer.business.growth._brief_body')
        </section>
    </div>
@endsection
