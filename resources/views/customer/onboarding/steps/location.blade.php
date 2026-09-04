@php($location = $onboarding->business?->primaryLocation)

<form method="POST" action="{{ route('customer.onboarding.location.store') }}">
    @csrf

    <x-select
        name="service_mode"
        label="Service mode"
        :options="collect(\App\Enums\Business\BusinessServiceMode::cases())->mapWithKeys(fn ($mode) => [$mode->value => ucwords(str_replace('_', ' ', $mode->value))])->all()"
        :selected="old('service_mode', $location->service_mode->value ?? null)"
        required
    />

    <x-input name="address_line_1" label="Address" type="text" value="{{ old('address_line_1', $location->address_line_1 ?? '') }}" />

    <div class="row">
        <div class="col-md-4">
            <x-input name="city" label="City" type="text" value="{{ old('city', $location->city ?? '') }}" />
        </div>
        <div class="col-md-4">
            <x-input name="region" label="Region" type="text" value="{{ old('region', $location->region ?? '') }}" />
        </div>
        <div class="col-md-4">
            <x-input name="postal_code" label="Postal code" type="text" value="{{ old('postal_code', $location->postal_code ?? '') }}" />
        </div>
    </div>

    <x-input name="country_code" label="Country code" type="text" maxlength="2" value="{{ old('country_code', $location->country_code ?? '') }}" required />

    <div class="form-check mb-1">
        <input class="form-check-input" type="checkbox" id="public_address" name="public_address" value="1" @checked(old('public_address', $location->public_address ?? false))>
        <label class="form-check-label" for="public_address">Show this address publicly</label>
    </div>

    <x-button type="submit" variant="primary" class="mt-2">Continue</x-button>
</form>
