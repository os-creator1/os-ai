@php($business = $onboarding->business)

<form method="POST" action="{{ route('customer.onboarding.business.store') }}">
    @csrf

    <x-input name="name" label="Business name" type="text" value="{{ old('name', $business->name ?? '') }}" required />

    <x-select
        name="industry"
        label="Industry"
        :options="collect(\App\Enums\Business\BusinessIndustry::cases())->mapWithKeys(fn ($industry) => [$industry->value => ucwords(str_replace('_', ' ', $industry->value))])->all()"
        :selected="old('industry', $business->industry->value ?? null)"
        required
    />

    <div class="mb-1">
        <label class="form-label" for="description">Description</label>
        <textarea class="form-control" id="description" name="description" maxlength="5000">{{ old('description', $business->description ?? '') }}</textarea>
    </div>

    <x-input name="email" label="Public email" type="email" value="{{ old('email', $business->email ?? '') }}" />

    <x-input name="phone" label="Phone" type="text" value="{{ old('phone', $business->phone ?? '') }}" />

    <x-input name="website_url" label="Website" type="text" value="{{ old('website_url', $business->website_url ?? '') }}" />

    @include('customer.business.partials.locale-fields', [
        'stored' => $business === null ? [] : ['country_code' => $business->country_code, 'timezone' => $business->timezone, 'currency_code' => $business->currency_code],
        'suggestDefaults' => $business === null,
    ])

    <x-button type="submit" variant="primary" class="mt-2">Continue</x-button>
</form>
