@php
    $business = $onboarding->business;
    $location = $business?->primaryLocation;
    $mode = old('service_mode', $location->service_mode->value ?? null);
    $cities = old('service_area_cities', $location->service_area_cities ?? []);
    $cities = is_array($cities) ? implode("\n", $cities) : (string) $cities;
    $modes = [
        'service_area' => ['I travel to my customers', 'Service-area business: events, mobile or on-site work.'],
        'storefront' => ['Customers visit my location', 'A shop, studio or office people come to.'],
        'hybrid' => ['Both', 'I have a location and I also travel.'],
        'online' => ['Online only', 'I work with customers remotely.'],
    ];
    $countryOptions = ['' => 'Choose a country'] + \App\Library\Business\BusinessLocaleOptions::withStoredValue(
        app(\App\Library\Business\BusinessLocaleOptions::class)->countries(),
        $location->country_code ?? $business->country_code ?? null
    );
@endphp

<h5>Where do you work?</h5>
<p class="text-muted">Local search starts with where you are and where you go. You can add more detail later.</p>

<form method="POST" action="{{ route('customer.onboarding.location.store') }}" data-role="location-form">
    @csrf

    <fieldset class="mb-2">
        <legend class="form-label text-label">How does your business operate?</legend>
        @foreach ($modes as $value => [$title, $hint])
            <div class="form-check mb-50">
                <input class="form-check-input" type="radio" name="service_mode" id="service_mode_{{ $value }}" value="{{ $value }}" @checked($mode === $value) required>
                <label class="form-check-label" for="service_mode_{{ $value }}"><strong>{{ $title }}</strong> <span class="text-muted">— {{ $hint }}</span></label>
            </div>
        @endforeach
    </fieldset>

    <div data-modes="service_area storefront hybrid">
        <div class="row">
            <div class="col-md-4">
                <x-input name="city" label="Main city" type="text" value="{{ old('city', $location->city ?? '') }}" maxlength="120" />
            </div>
            <div class="col-md-4">
                <x-input name="region" label="State / region" type="text" value="{{ old('region', $location->region ?? '') }}" maxlength="120" />
            </div>
            <div class="col-md-4" data-modes="storefront hybrid">
                <x-input name="postal_code" label="Postal code" type="text" value="{{ old('postal_code', $location->postal_code ?? '') }}" maxlength="32" />
            </div>
        </div>
    </div>

    <div data-modes="storefront hybrid">
        <x-input name="address_line_1" label="Street address" type="text" value="{{ old('address_line_1', $location->address_line_1 ?? '') }}" maxlength="255" />
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="public_address" name="public_address" value="1" @checked(old('public_address', $location->public_address ?? false))>
            <label class="form-check-label" for="public_address">Show this address publicly on my website and listings</label>
        </div>
    </div>

    <x-select name="country_code" label="Country" :options="$countryOptions" :selected="old('country_code', $location->country_code ?? $business->country_code ?? null)" required />

    <div data-modes="service_area hybrid storefront">
        <div class="mb-1">
            <label class="form-label" for="service_area_cities">Other cities you serve (optional)</label>
            <textarea class="form-control" id="service_area_cities" name="service_area_cities" rows="3" placeholder="One per line">{{ $cities }}</textarea>
        </div>
        <div data-modes="service_area hybrid">
            <x-input name="service_radius_km" label="Service radius in km (optional)" type="number" min="1" max="1000" value="{{ old('service_radius_km', $location->service_radius_km ?? '') }}" />
        </div>
    </div>

    <x-button type="submit" variant="primary" class="mt-2">Continue</x-button>
</form>

<script>
    // Progressive enhancement: only the fields the chosen operating mode needs are shown.
    // Without script every field is visible and the server applies the same rules.
    (function () {
        var form = document.querySelector('[data-role="location-form"]');
        if (!form) { return; }
        function sync() {
            var checked = form.querySelector('input[name="service_mode"]:checked');
            var mode = checked ? checked.value : null;
            form.querySelectorAll('[data-modes]').forEach(function (el) {
                el.hidden = mode !== null && el.getAttribute('data-modes').split(' ').indexOf(mode) === -1;
            });
        }
        form.addEventListener('change', sync);
        sync();
    })();
</script>
