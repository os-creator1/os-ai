@extends('layouts/contentLayoutMaster')

@section('title', 'Business Setup')

@section('content')
    <section id="business-onboarding">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Business Setup</h4>
                    </div>
                    <div class="card-body">
                        <ul class="nav nav-pills mb-2 text-uppercase">
                            @foreach (['goals' => 'Goals', 'business' => 'Business', 'location' => 'Location', 'services' => 'Services', 'assets' => 'Assets', 'analysis' => 'Analysis', 'results' => 'Results', 'complete' => 'Complete'] as $stepValue => $label)
                                <li class="nav-item">
                                    <span class="nav-link {{ $step->value === $stepValue ? 'active' : '' }}">{{ $label }}</span>
                                </li>
                            @endforeach
                        </ul>

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @include('customer.onboarding.steps.' . $step->value, ['onboarding' => $onboarding])
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
