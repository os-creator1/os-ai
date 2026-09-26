@extends('layouts/contentLayoutMaster')

{{--
    Contract 07 correction — the client owner's own draft-activation step.
    AgencyClientProvisioningManager::accept() created this Business as Draft
    with placeholder identity (industry Other, country US, timezone UTC,
    currency USD) and an address-less storefront primary location; Agency
    "View As" requires Active. This form is the ONLY way that Business ever
    becomes Active (BusinessManager::activateClientBusiness()), and it is
    reachable only by the Workspace's own owner
    (ClientBusinessActivationController::resolveOwnedWorkspace()) — never by
    the inviting Agency.
--}}

@section('title', 'Finish setting up your Business')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection

@section('content')
    <section id="client-business-activation">
        <div class="row">
            <div class="col-12">
                <x-card title="Finish setting up {{ $business->name }}">
                    <p class="text-caption mb-3" data-role="activation-explanation">
                        Your Agency created this Business for you with placeholder details so you could get
                        started right away. Review and confirm the real details below before it goes live —
                        nothing here has been shown to anyone yet.
                    </p>

                    @if (session('flash_success'))
                        <x-alert variant="success" data-role="flash-success">{{ session('flash_success') }}</x-alert>
                    @endif

                    @if ($errors->any())
                        <x-alert variant="danger" data-role="validation-summary">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-alert>
                    @endif

                    <form method="POST" action="{{ route('customer.workspaces.businesses.activate.store', [$workspace->uid, $business->uid]) }}" data-role="activate-business-form">
                        @csrf

                        <h5 class="mb-1">Business details</h5>

                        <x-select
                            name="industry"
                            label="Industry"
                            :options="collect(\App\Enums\Business\BusinessIndustry::cases())->mapWithKeys(fn ($industry) => [$industry->value => ucwords(str_replace('_', ' ', $industry->value))])->all()"
                            :selected="old('industry', $business->industry->value)"
                            required
                        />

                        <x-input name="industry_other" label="Industry (other)" type="text" maxlength="255" value="{{ old('industry_other', $business->industry_other) }}" />

                        @include('customer.business.partials.locale-fields', [
                            'stored' => ['country_code' => $business->country_code, 'timezone' => $business->timezone, 'currency_code' => $business->currency_code],
                        ])

                        <hr class="my-2">

                        <h5 class="mb-1">Primary location</h5>

                        <x-input name="location_name" label="Location name" type="text" help="For example, the neighbourhood or street, so it's recognizable if you add more locations later." value="{{ old('location_name', $location?->name ?? '') }}" />

                        <x-select
                            name="service_mode"
                            label="How customers reach this location"
                            :options="[
                                'storefront' => 'Storefront — customers visit you',
                                'service_area' => 'Service area — you go to customers',
                                'hybrid' => 'Both — a storefront that also serves an area',
                                'online' => 'Online only — no physical address',
                            ]"
                            :selected="old('service_mode', $location?->service_mode?->value)"
                            required
                        />

                        <x-input name="address_line_1" label="Address" type="text" value="{{ old('address_line_1', $location?->address_line_1 ?? '') }}" />
                        <x-input name="address_line_2" label="Address line 2" type="text" value="{{ old('address_line_2', $location?->address_line_2 ?? '') }}" />

                        <div class="row">
                            <div class="col-md-4">
                                <x-input name="city" label="City" type="text" value="{{ old('city', $location?->city ?? '') }}" />
                            </div>
                            <div class="col-md-4">
                                <x-input name="region" label="State or region" type="text" value="{{ old('region', $location?->region ?? '') }}" />
                            </div>
                            <div class="col-md-4">
                                <x-input name="postal_code" label="Postal code" type="text" value="{{ old('postal_code', $location?->postal_code ?? '') }}" />
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <x-input name="location_country_code" label="Location country code" type="text" maxlength="2" help="Two letters, for example US or CA." value="{{ old('location_country_code', $location?->country_code ?? $business->country_code ?? '') }}" required />
                            </div>
                            <div class="col-md-4">
                                <x-input name="service_radius_km" label="Service radius (km)" type="number" min="1" max="1000" help="Only for a service area." value="{{ old('service_radius_km', $location?->service_radius_km ?? '') }}" />
                            </div>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="public_address" name="public_address" value="1" @checked(old('public_address', $location?->public_address ?? false))>
                            <label class="form-check-label" for="public_address">Show this address publicly</label>
                        </div>

                        <hr class="my-2">

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="confirm" name="confirm" value="1" required>
                            <label class="form-check-label" for="confirm">
                                I have reviewed these details and confirm they are correct.
                            </label>
                        </div>

                        <x-button type="submit" variant="primary" data-role="activate-business-submit">Activate this Business</x-button>
                    </form>
                </x-card>
            </div>
        </div>
    </section>
@endsection
