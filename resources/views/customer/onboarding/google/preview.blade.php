@extends('layouts/contentLayoutMaster')

@section('title', 'Review your business')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection

@section('page-style')
<style>
    .gbp-card { max-width: 720px; margin: 0 auto; border: 1px solid var(--bs-border-color, #e4e6ef); border-radius: 1rem; overflow: hidden; background: var(--bs-body-bg, #fff); box-shadow: 0 6px 24px rgba(34, 41, 47, .08); }
    .gbp-card__head { padding: 1.25rem 1.25rem 1rem; background: linear-gradient(135deg, rgba(115, 103, 240, .14), rgba(40, 199, 111, .10)); border-bottom: 1px solid var(--bs-border-color, #e4e6ef); }
    .gbp-card__body { padding: 1rem 1.25rem; }
    .gbp-section { margin-bottom: 1.25rem; }
    .gbp-section > h6 { text-transform: uppercase; letter-spacing: .04em; font-size: .75rem; color: var(--bs-secondary-color, #6e6b7b); margin-bottom: .5rem; }
    .gbp-row { padding: .6rem 0; border-bottom: 1px solid var(--bs-border-color-translucent, rgba(0,0,0,.06)); }
    .gbp-label { font-size: .8rem; color: var(--bs-secondary-color, #6e6b7b); margin: 0; }
    .gbp-inline { width: 100%; border: 0; border-bottom: 1px dashed transparent; background: transparent; color: inherit; font-size: 1.05rem; padding: .15rem 0; border-radius: 0; resize: vertical; }
    .gbp-inline:hover { border-bottom-color: var(--bs-border-color, #c9cbd6); }
    .gbp-inline:focus { outline: 0; border-bottom: 1px solid var(--bs-primary, #7367f0); }
    .gbp-inline::placeholder { color: var(--bs-secondary-color, #9a98a6); font-style: italic; }
    .gbp-title .gbp-inline { font-size: 1.5rem; font-weight: 600; }
    .gbp-help { font-size: .78rem; color: var(--bs-secondary-color, #6e6b7b); margin-top: .15rem; }
    .gbp-error { font-size: .8rem; color: var(--bs-danger, #ea5455); margin-top: .15rem; }
    .gbp-badge { font-size: .68rem; font-weight: 600; padding: .1rem .5rem; border-radius: 999px; white-space: nowrap; }
    .gbp-badge--imported { background: rgba(40, 199, 111, .15); color: #1f9d57; }
    .gbp-badge--unverified { background: rgba(255, 159, 67, .18); color: #b86a1d; }
    .gbp-badge--suggested { background: rgba(0, 207, 232, .15); color: #0a8da0; }
    .gbp-badge--saved { background: rgba(115, 103, 240, .15); color: #5b4fd6; }
    .gbp-badge--edited { background: rgba(115, 103, 240, .15); color: #5b4fd6; }
    .gbp-badge--missing { background: rgba(130, 134, 139, .18); color: #5e5873; }
    .gbp-badge--verified { background: rgba(40, 199, 111, .2); color: #1f9d57; }
    .gbp-badge--not-verified { background: rgba(255, 159, 67, .2); color: #b86a1d; }
    .gbp-chip { display: inline-block; font-size: .78rem; padding: .1rem .55rem; border-radius: 999px; background: rgba(130, 134, 139, .16); margin: 0 .25rem .25rem 0; }
    .gbp-actions { position: sticky; bottom: 0; z-index: 5; background: var(--bs-body-bg, #fff); border-top: 1px solid var(--bs-border-color, #e4e6ef); padding: .75rem 1.25rem; }
    .gbp-modes { display: grid; gap: .4rem; }
    .gbp-mode { display: flex; gap: .6rem; align-items: flex-start; padding: .55rem .7rem; border: 1px solid var(--bs-border-color, #e4e6ef); border-radius: .6rem; cursor: pointer; }
    .gbp-mode input { margin-top: .25rem; }
    @media (max-width: 575.98px) { .gbp-card { border-radius: .75rem; } .gbp-card__head, .gbp-card__body, .gbp-actions { padding-left: .9rem; padding-right: .9rem; } }
</style>
@endsection

@section('content')
@php
    $f = $card['fields'];
    $industryOptions = ['' => 'Choose the closest match'] + collect(\App\Enums\Business\BusinessIndustry::cases())
        ->mapWithKeys(fn ($industry) => [$industry->value => $industry->label()])->all();
    $industryValue = old('industry', $f['industry']['value']);
    $mode = old('service_mode', $f['service_mode']['value']);
    $modes = [
        'storefront' => ['Customers visit me', 'A shop, studio or office people come to.'],
        'service_area' => ['I travel to my customers', 'Events, mobile or on-site work.'],
        'hybrid' => ['Both', 'I have a location and I also travel.'],
        'online' => ['Online only', 'I work with customers remotely.'],
    ];
    $websiteOptions = [
        'existing' => 'Use this address as my existing website',
        'build' => 'Build a new website with MotionGrove',
        'later' => 'I’ll decide later',
    ];
    $statusLabels = \App\Library\Business\GoogleOnboardingPreview::STATUS_LABELS;
@endphp

<section id="business-onboarding-google-preview">
    <div class="gbp-card" data-role="google-listing-card">
        <form method="POST" action="{{ route('customer.onboarding.google.confirm') }}" data-role="google-confirm-form" novalidate>
            @csrf
            <input type="hidden" name="snapshot" value="{{ $snapshotToken }}">

            <header class="gbp-card__head">
                <div class="d-flex flex-wrap align-items-center gap-50 mb-50">
                    @if ($card['verified'])
                        <span class="gbp-badge gbp-badge--verified">Verified on Google</span>
                    @else
                        <span class="gbp-badge gbp-badge--not-verified">Not verified by Google</span>
                    @endif
                    @if ($card['openStatus'] === 'OPEN')<span class="gbp-chip">Open for business</span>@endif
                </div>
                <div class="gbp-title">
                    @include('customer.onboarding.google._field', ['f' => $f['name'], 'label' => 'Business name', 'placeholder' => 'Your business name'])
                </div>
                @if ($f['industry']['category'])
                    <div class="mt-50">
                        <span class="gbp-chip">{{ $f['industry']['category'] }}</span>
                        @foreach ($card['additionalCategories'] as $extra)<span class="gbp-chip">{{ $extra }}</span>@endforeach
                    </div>
                @endif
            </header>

            <div class="gbp-card__body">
                @if ($errors->any())
                    <x-alert variant="danger">
                        <strong>Please check the highlighted details.</strong>
                    </x-alert>
                @endif
                @foreach ($card['notes'] as $note)
                    <x-alert variant="warning">{{ $note }}</x-alert>
                @endforeach

                <p class="text-muted small mb-1">
                    Check each detail and tap any of them to edit. Nothing is saved until you confirm, and nothing is ever sent back to Google.
                </p>

                <div class="gbp-section">
                    <h6>Your business</h6>
                    <div class="gbp-row" data-field="industry">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-50 mb-25">
                            <label class="gbp-label" for="gbp-industry">Kind of business <span class="text-danger" aria-hidden="true">*</span></label>
                            <span class="gbp-badge gbp-badge--{{ $f['industry']['status'] }}" data-role="status" data-imported="{{ $f['industry']['imported'] }}" data-saved="{{ $f['industry']['saved'] }}">{{ $statusLabels[$f['industry']['status']] }}</span>
                        </div>
                        <select class="form-select" id="gbp-industry" name="industry" aria-required="true">
                            @foreach ($industryOptions as $value => $text)
                                <option value="{{ $value }}" @selected($industryValue === $value)>{{ $text }}</option>
                            @endforeach
                        </select>
                        <div data-role="industry-other" class="mt-50">
                            <input class="gbp-inline" name="industry_other" type="text" maxlength="255" value="{{ old('industry_other', $f['industry']['other'] ?? $f['industry']['category']) }}" placeholder="Describe your kind of business" aria-label="Describe your kind of business">
                        </div>
                        @if ($f['industry']['category'] && $f['industry']['imported'] === null)
                            <div class="gbp-help">Google lists you as “{{ $f['industry']['category'] }}”. Pick the closest match, or “Other”.</div>
                        @endif
                        @error('industry')<div class="gbp-error">{{ $message }}</div>@enderror
                        @error('industry_other')<div class="gbp-error">{{ $message }}</div>@enderror
                    </div>
                    @include('customer.onboarding.google._field', ['f' => $f['phone'], 'label' => 'Phone', 'type' => 'tel', 'max' => 50, 'placeholder' => 'Add a public phone number'])
                    @include('customer.onboarding.google._field', ['f' => $f['description'], 'label' => 'Short description', 'as' => 'textarea', 'max' => 5000, 'placeholder' => 'Optional. Add one later if you like.'])
                </div>

                <div class="gbp-section">
                    <h6>Where you work</h6>
                    <div class="gbp-row" data-field="service_mode">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-50 mb-50">
                            <span class="gbp-label" id="gbp-mode-label">How do customers reach you? <span class="text-danger" aria-hidden="true">*</span></span>
                            <span class="gbp-badge gbp-badge--{{ $f['service_mode']['status'] }}" data-role="status" data-imported="{{ $f['service_mode']['imported'] }}" data-saved="{{ $f['service_mode']['saved'] }}">{{ $statusLabels[$f['service_mode']['status']] }}</span>
                        </div>
                        <div class="gbp-modes" role="radiogroup" aria-labelledby="gbp-mode-label">
                            @foreach ($modes as $value => [$title, $hint])
                                <label class="gbp-mode" for="gbp-mode-{{ $value }}">
                                    <input class="form-check-input" type="radio" name="service_mode" id="gbp-mode-{{ $value }}" value="{{ $value }}" @checked($mode === $value)>
                                    <span><strong>{{ $title }}</strong><br><span class="text-muted small">{{ $hint }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        @error('service_mode')<div class="gbp-error">{{ $message }}</div>@enderror
                    </div>
                    @include('customer.onboarding.google._field', ['f' => $f['city'], 'label' => 'City', 'visibleIn' => 'storefront service_area hybrid', 'max' => 120, 'placeholder' => 'Your main city'])
                    @include('customer.onboarding.google._field', ['f' => $f['region'], 'label' => 'State / region', 'visibleIn' => 'storefront service_area hybrid', 'max' => 120, 'help' => 'Needed for addresses in some countries, such as the US and Canada.', 'placeholder' => 'State or region'])
                    @include('customer.onboarding.google._field', ['f' => $f['address_line_1'], 'label' => 'Street address', 'visibleIn' => 'storefront hybrid', 'placeholder' => 'Street address', 'help' => 'Google does not share your street address, so add it here if customers visit you.'])
                    <div class="gbp-row" data-modes="storefront hybrid">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="gbp-public-address" name="public_address" value="1" @checked($publicAddress)>
                            <label class="form-check-label" for="gbp-public-address">Show this address publicly on my website and listings</label>
                        </div>
                    </div>
                </div>

                <div class="gbp-section">
                    <h6>Your main service</h6>
                    @include('customer.onboarding.google._field', ['f' => $f['main_service'], 'label' => 'Main service', 'placeholder' => 'For example, Photo Booth Rental', 'help' => 'Google gives a category, not a list of services. Confirm the one customers search for. You can add more later.'])
                </div>

                <div class="gbp-section">
                    <h6>Website</h6>
                    @include('customer.onboarding.google._field', ['f' => $f['website_url'], 'label' => 'Website address', 'type' => 'text', 'max' => 2048, 'placeholder' => 'example.com'])
                    <div class="gbp-row">
                        <fieldset>
                            <legend class="gbp-label mb-50">What should we do about your website?</legend>
                            @foreach ($websiteOptions as $value => $text)
                                <div class="form-check mb-25">
                                    <input class="form-check-input" type="radio" name="website_choice" id="gbp-web-{{ $value }}" value="{{ $value }}" @checked($websiteChoice === $value)>
                                    <label class="form-check-label" for="gbp-web-{{ $value }}">{{ $text }}</label>
                                </div>
                            @endforeach
                            <div class="gbp-help">An imported address is only saved. We do not connect, check or change that site during setup.</div>
                        </fieldset>
                        @error('website_choice')<div class="gbp-error">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="gbp-section">
                    <h6>Your preferences</h6>
                    <p class="gbp-help mb-50">Google does not tell us these reliably, so they are always your choice.</p>
                    @include('customer.business.partials.locale-fields', [
                        'stored' => ['country_code' => $business->country_code, 'timezone' => $business->timezone, 'currency_code' => $business->currency_code],
                        'suggestDefaults' => false,
                        'colClass' => 'col-12 col-md-4',
                    ])
                </div>
            </div>

            <div class="gbp-actions d-flex flex-wrap gap-1 align-items-center">
                <x-button type="submit" variant="primary" data-role="confirm-google">Confirm and continue</x-button>
                <a class="btn btn-link" href="{{ route('customer.onboarding.google.show') }}">Choose another profile</a>
                <a class="btn btn-link" href="{{ route('customer.onboarding.show', ['step' => 'business', 'manual' => 1]) }}">Set up manually</a>
            </div>
        </form>
    </div>
</section>

<script>
    (function () {
        var form = document.querySelector('[data-role="google-confirm-form"]');
        if (!form) { return; }
        var labels = @json($statusLabels);

        // Live status: a field the customer changes becomes "Edited", and goes back when it matches again.
        form.querySelectorAll('.gbp-row[data-field]').forEach(function (row) {
            var badge = row.querySelector('[data-role="status"]');
            var inputs = row.querySelectorAll('input[type="text"], input[type="tel"], textarea, select, input[type="radio"]');
            if (!badge || !inputs.length) { return; }
            var original = badge.className;
            var originalText = badge.textContent;
            function current() {
                var radio = row.querySelector('input[type="radio"]');
                if (radio) { var c = row.querySelector('input[type="radio"]:checked'); return c ? c.value : ''; }
                return (inputs[0].value || '').trim();
            }
            var initial = current();
            function sync() {
                var value = current();
                if (value === initial) { badge.className = original; badge.textContent = originalText; return; }
                if (value === '') { badge.className = 'gbp-badge gbp-badge--missing'; badge.textContent = labels.missing; return; }
                badge.className = 'gbp-badge gbp-badge--edited';
                badge.textContent = labels.edited;
            }
            row.addEventListener('input', sync);
            row.addEventListener('change', sync);
        });

        // "Use Google's" replaces a value already saved here, and only when the customer asks for it.
        form.querySelectorAll('[data-use-google]').forEach(function (button) {
            button.hidden = false;
            button.addEventListener('click', function () {
                var target = document.getElementById(button.getAttribute('data-target'));
                if (target) { target.value = button.getAttribute('data-use-google'); target.dispatchEvent(new Event('input', { bubbles: true })); }
            });
        });

        // Only the address fields the chosen way of working needs are shown; without script they all are.
        function syncModes() {
            var checked = form.querySelector('input[name="service_mode"]:checked');
            var mode = checked ? checked.value : null;
            form.querySelectorAll('[data-modes]').forEach(function (el) {
                var allowed = el.getAttribute('data-modes');
                if (!allowed) { return; }
                el.hidden = mode !== null && allowed.split(' ').indexOf(mode) === -1;
            });
        }
        form.addEventListener('change', syncModes);
        syncModes();

        // The "Other" description only matters for the Other kind of business.
        var industry = document.getElementById('gbp-industry');
        var other = form.querySelector('[data-role="industry-other"]');
        function syncIndustry() {
            if (!industry || !other) { return; }
            var isOther = industry.value === 'other';
            other.hidden = !isOther;
            other.querySelectorAll('input').forEach(function (i) { i.disabled = !isOther; });
        }
        if (industry) { industry.addEventListener('change', syncIndustry); }
        syncIndustry();
    })();
</script>
@endsection
