@extends('layouts/authCover')

@section('title', $isAgency ? __('Tell us about your agency') : __('Tell us about your business'))

@section('auth-form')
    {{--
        STEP 3 — BUSINESS. Only the minimum provisioning facts. Everything
        else (website, services, messaging, calendar, Business Stripe, SEO)
        is configured inside the product after signup.

        On the Agency branch this asks for the agency's own basics and never
        a niche: nothing about a client is invented at signup.
    --}}
    @include('auth.signup._progress', ['step' => 3])

    <h1 class="card-title fw-bold mb-1 h2">{{ $isAgency ? __('Tell us about your agency') : __('Tell us about your business') }}</h1>
    @include('auth.signup._plan-line')

    @include('auth.partials._flash-summary')

    <form class="auth-register-form" method="POST" action="{{ route('register.business.store') }}" novalidate>
        @csrf
        <div class="mb-1">
            <label class="form-label" for="business_name">{{ $isAgency ? __('Agency name') : __('Business name') }}</label>
            <input id="business_name" name="business_name" type="text" class="form-control @error('business_name') is-invalid @enderror"
                   value="{{ old('business_name', $business['business_name'] ?? '') }}" required autofocus>
            @include('auth.signup._field-error', ['field' => 'business_name'])
        </div>

        @unless ($isAgency)
            <div class="mb-1">
                <label class="form-label" for="industry">{{ __('What kind of business is it?') }}</label>
                <select id="industry" name="industry" class="form-select @error('industry') is-invalid @enderror" required>
                    <option value="">{{ __('Choose a business type') }}</option>
                    @foreach ($industries as $industry)
                        <option value="{{ $industry->value }}" @selected(old('industry', $business['industry'] ?? '') === $industry->value)>
                            {{ ucwords(str_replace('_', ' ', $industry->value)) }}
                        </option>
                    @endforeach
                </select>
                @include('auth.signup._field-error', ['field' => 'industry'])
            </div>
        @endunless

        <div class="mb-1">
            <label class="form-label" for="country_code">{{ __('Country') }}</label>
            <select id="country_code" name="country_code" class="form-select @error('country_code') is-invalid @enderror" required>
                <option value="">{{ __('Choose a country') }}</option>
                @foreach ($countries as $code => $label)
                    <option value="{{ $code }}" @selected(old('country_code', $business['country_code'] ?? 'US') === $code)>{{ $label }}</option>
                @endforeach
            </select>
            @include('auth.signup._field-error', ['field' => 'country_code'])
        </div>

        <div class="mb-2">
            <label class="form-label" for="timezone">{{ __('Time zone') }}</label>
            <select id="timezone" name="timezone" class="form-select @error('timezone') is-invalid @enderror" required data-timezone-select
                    data-old="{{ old('timezone', $business['timezone'] ?? '') }}" data-default="{{ config('app.timezone') }}">
                <option value="">{{ __('Choose a timezone') }}</option>
                @foreach ($timezones as $identifier)
                    <option value="{{ $identifier }}" @selected(old('timezone', $business['timezone'] ?? config('app.timezone')) === $identifier)>{{ $identifier }}</option>
                @endforeach
            </select>
            @include('auth.signup._field-error', ['field' => 'timezone'])
        </div>

        <button type="submit" class="btn btn-primary w-100">{{ __('Continue') }}</button>
    </form>

    <p class="text-center mt-2"><a href="{{ route('register.account') }}">{{ __('Back') }}</a></p>
@endsection

@push('scripts')
    <script>
        // Prefer the browser's own timezone as the initial selection, but only
        // on a first visit (no earlier answer to preserve) and only when it is
        // one of the offered options. Never geolocates; any failure leaves the
        // server-rendered default in place.
        (function () {
            var select = document.querySelector('[data-timezone-select]');

            if (! select || select.getAttribute('data-old')) {
                return;
            }

            try {
                var detected = Intl.DateTimeFormat().resolvedOptions().timeZone;
                var exists = detected && Array.prototype.some.call(select.options, function (option) {
                    return option.value === detected;
                });

                if (exists) {
                    select.value = detected;
                }
            } catch (error) {
                // No usable browser timezone: the server-rendered default stands.
            }
        })();
    </script>
@endpush
