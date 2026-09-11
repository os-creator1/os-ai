{{--
    Customer Experience Slice 1A — the location details form shared by
    create and edit. $location is null when creating. $modes are the
    service modes this form offers (a new location is always a physical
    place; editing keeps whatever mode the location already has).
--}}
@php
    $modeLabels = [
        'storefront' => 'Storefront — customers visit you',
        'service_area' => 'Service area — you go to customers',
        'hybrid' => 'Both — a storefront that also serves an area',
        'online' => 'Online only — no physical address',
    ];
@endphp

<x-input name="name" label="Location name" type="text" help="For example, the neighbourhood or street, so you can tell your locations apart." value="{{ old('name', $location->name ?? '') }}" :required="$nameRequired" />

<x-select
    name="service_mode"
    label="How customers reach this location"
    :options="collect($modes)->mapWithKeys(fn ($mode) => [$mode => $modeLabels[$mode] ?? $mode])->all()"
    :selected="old('service_mode', $location?->service_mode?->value)"
    required
/>

<x-input name="address_line_1" label="Address" type="text" value="{{ old('address_line_1', $location->address_line_1 ?? '') }}" />
<x-input name="address_line_2" label="Address line 2" type="text" value="{{ old('address_line_2', $location->address_line_2 ?? '') }}" />

<div class="row">
    <div class="col-md-4">
        <x-input name="city" label="City" type="text" value="{{ old('city', $location->city ?? '') }}" />
    </div>
    <div class="col-md-4">
        <x-input name="region" label="State or region" type="text" value="{{ old('region', $location->region ?? '') }}" />
    </div>
    <div class="col-md-4">
        <x-input name="postal_code" label="Postal code" type="text" value="{{ old('postal_code', $location->postal_code ?? '') }}" />
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <x-input name="country_code" label="Country code" type="text" maxlength="2" help="Two letters, for example US or CA." value="{{ old('country_code', $location->country_code ?? $business->country_code ?? '') }}" required />
    </div>
    <div class="col-md-4">
        <x-input name="service_radius_km" label="Service radius (km)" type="number" min="1" max="1000" help="Only for a service area." value="{{ old('service_radius_km', $location->service_radius_km ?? '') }}" />
    </div>
</div>

<div class="form-check mb-2">
    <input class="form-check-input" type="checkbox" id="public_address" name="public_address" value="1" @checked(old('public_address', $location->public_address ?? false))>
    <label class="form-check-label" for="public_address">Show this address publicly</label>
</div>
