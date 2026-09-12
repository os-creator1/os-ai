@extends('layouts/contentLayoutMaster')

@section('title', 'Business Setup')

@if ($step->value === 'business')
    @section('vendor-style')
        <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
    @endsection

    @section('vendor-script')
        <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
    @endsection
@endif

@section('content')
    <section id="business-onboarding">
        <div class="row">
            <div class="col-12">
                <x-card title="Business Setup">
                    <ul class="nav nav-pills mb-2 text-uppercase">
                        @foreach (['goals' => 'Goals', 'business' => 'Business', 'location' => 'Location', 'services' => 'Services', 'assets' => 'Assets', 'analysis' => 'Analysis', 'results' => 'Results', 'complete' => 'Complete'] as $stepValue => $label)
                            <li class="nav-item">
                                <span class="nav-link {{ $step->value === $stepValue ? 'active' : '' }}" @if ($step->value === $stepValue) aria-current="step" @endif>{{ $label }}</span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($errors->any())
                        <x-alert variant="danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-alert>
                    @endif

                    @include('customer.onboarding.steps.' . $step->value, ['onboarding' => $onboarding])
                </x-card>
            </div>
        </div>
    </section>
@endsection
