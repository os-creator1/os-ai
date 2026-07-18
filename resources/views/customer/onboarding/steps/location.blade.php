@php($location = $onboarding->business?->primaryLocation)

<form method="POST" action="{{ route('customer.onboarding.location.store') }}">
    @csrf

    <div class="mb-1">
        <label class="form-label" for="service_mode">Service mode</label>
        <select class="form-control" id="service_mode" name="service_mode" required>
            @foreach (\App\Enums\Business\BusinessServiceMode::cases() as $mode)
                <option value="{{ $mode->value }}" @selected(old('service_mode', $location->service_mode->value ?? null) === $mode->value)>
                    {{ ucwords(str_replace('_', ' ', $mode->value)) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="mb-1">
        <label class="form-label" for="address_line_1">Address</label>
        <input type="text" class="form-control" id="address_line_1" name="address_line_1" value="{{ old('address_line_1', $location->address_line_1 ?? '') }}">
    </div>

    <div class="row">
        <div class="col-md-4 mb-1">
            <label class="form-label" for="city">City</label>
            <input type="text" class="form-control" id="city" name="city" value="{{ old('city', $location->city ?? '') }}">
        </div>
        <div class="col-md-4 mb-1">
            <label class="form-label" for="region">Region</label>
            <input type="text" class="form-control" id="region" name="region" value="{{ old('region', $location->region ?? '') }}">
        </div>
        <div class="col-md-4 mb-1">
            <label class="form-label" for="postal_code">Postal code</label>
            <input type="text" class="form-control" id="postal_code" name="postal_code" value="{{ old('postal_code', $location->postal_code ?? '') }}">
        </div>
    </div>

    <div class="mb-1">
        <label class="form-label" for="country_code">Country code</label>
        <input type="text" class="form-control" id="country_code" name="country_code" maxlength="2" value="{{ old('country_code', $location->country_code ?? '') }}" required>
    </div>

    <div class="form-check mb-1">
        <input class="form-check-input" type="checkbox" id="public_address" name="public_address" value="1" @checked(old('public_address', $location->public_address ?? false))>
        <label class="form-check-label" for="public_address">Show this address publicly</label>
    </div>

    <button type="submit" class="btn btn-primary mt-2">Continue</button>
</form>
