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
                    <ol class="nav nav-pills mb-2 text-uppercase list-unstyled" aria-label="Setup progress">
                        @foreach ($steps as $number => $item)
                            @php($reachable = in_array($item->value, $reachableSteps, true))
                            <li class="nav-item">
                                @if ($reachable && $item !== $step)
                                    <a class="nav-link" href="{{ route('customer.onboarding.show', ['step' => $item->value]) }}">{{ $number + 1 }}. {{ $item->label() }}</a>
                                @else
                                    <span class="nav-link {{ $item === $step ? 'active' : '' }}" @if ($item === $step) aria-current="step" @endif>{{ $number + 1 }}. {{ $item->label() }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>

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
