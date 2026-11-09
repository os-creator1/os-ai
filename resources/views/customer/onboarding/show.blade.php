@extends('layouts/contentLayoutMaster')

@section('title', 'Business Setup')

@if (in_array($step->value, ['business', 'location'], true))
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
                    @php
                        $stepNumber = (int) array_search($step, $steps, true) + 1;
                        $stepTotal = count($steps);
                    @endphp
                    <nav class="mb-2" aria-label="Setup progress" data-role="setup-progress">
                        <div class="d-flex justify-content-between align-items-center mb-50">
                            <span class="fw-semibold" aria-current="step">Step {{ $stepNumber }} of {{ $stepTotal }} · {{ $step->label() }}</span>
                            <span class="text-muted small">
                                @foreach ($steps as $item)
                                    @if ($item !== $step && in_array($item->value, $reachableSteps, true))
                                        <a class="ms-50" href="{{ route('customer.onboarding.show', ['step' => $item->value]) }}">{{ $item->label() }}</a>
                                    @endif
                                @endforeach
                            </span>
                        </div>
                        <div class="progress" style="height: 6px" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $stepTotal }}" aria-valuenow="{{ $stepNumber }}">
                            <div class="progress-bar" style="width: {{ round($stepNumber / $stepTotal * 100) }}%"></div>
                        </div>
                    </nav>

                    @if (! empty($googleLink))
                        @if ($googleLink['linked'])
                            <x-alert variant="success" data-role="google-link-state">Your Google Business Profile is linked to your location.</x-alert>
                        @else
                            <x-alert variant="warning" data-role="google-link-state">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                    <span>Your Google profile is not linked yet. Your setup progress is safe.</span>
                                    @if ($googleLink['canRetry'])
                                        <form method="POST" action="{{ route('customer.onboarding.google.link') }}" class="m-0">
                                            @csrf
                                            <x-button type="submit" variant="primary" size="sm" data-role="retry-google-link">Retry linking</x-button>
                                        </form>
                                    @endif
                                </div>
                            </x-alert>
                        @endif
                    @endif

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
