@php
    $business = $onboarding->business;
    $industryOptions = ['' => 'Choose your kind of business'] + collect(\App\Enums\Business\BusinessIndustry::cases())
        ->mapWithKeys(fn ($industry) => [$industry->value => $industry->label()])->all();
@endphp

<h5>Tell us about your business</h5>
<p class="text-muted">Just the basics. The name and kind of business tell MotionGrove what your website and search listings are about.</p>

<form method="POST" action="{{ route('customer.onboarding.business.store') }}" data-role="business-form">
    @csrf

    <x-input name="name" label="Business name" type="text" value="{{ old('name', $business->name ?? '') }}" required maxlength="255" />

    <x-select
        name="industry"
        label="What kind of business is it?"
        :options="$industryOptions"
        :selected="old('industry', $business->industry->value ?? null)"
        required
    />

    <div data-role="industry-other">
        <x-input name="industry_other" label="Describe your kind of business" type="text" value="{{ old('industry_other', $business->industry_other ?? '') }}" maxlength="255" />
    </div>

    <div class="mb-1">
        <label class="form-label" for="description">Short description (optional)</label>
        <textarea class="form-control" id="description" name="description" maxlength="5000" rows="3">{{ old('description', $business->description ?? '') }}</textarea>
    </div>

    <div class="row">
        <div class="col-md-6">
            <x-input name="email" label="Public contact email (optional)" type="email" value="{{ old('email', $business->email ?? '') }}" help="Shown to customers. Your sign-in email stays private unless you enter it here." />
        </div>
        <div class="col-md-6">
            <x-input name="phone" label="Public business phone (optional)" type="text" value="{{ old('phone', $business->phone ?? '') }}" />
        </div>
    </div>

    @include('customer.business.partials.locale-fields', [
        'stored' => $business === null ? [] : ['country_code' => $business->country_code, 'timezone' => $business->timezone, 'currency_code' => $business->currency_code],
        'suggestDefaults' => $business === null,
    ])

    <x-button type="submit" variant="primary" class="mt-2">Continue</x-button>
</form>

<script>
    // Progressive enhancement: the "other" description only matters for the Other niche.
    (function () {
        var select = document.getElementById('industry');
        var holder = document.querySelector('[data-role="industry-other"]');
        if (!select || !holder) { return; }
        function sync() { holder.hidden = select.value !== 'other'; }
        select.addEventListener('change', sync);
        if (window.jQuery) { window.jQuery(select).on('change', sync); }
        sync();
    })();
</script>
