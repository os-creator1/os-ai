@php
    $business = $onboarding->business;
    $services = $business?->services()->where('status', \App\Enums\Business\BusinessServiceStatus::Active)->orderBy('sort_order')->orderBy('id')->get() ?? collect();
    $savedNames = $services->map(fn ($service) => mb_strtolower(trim($service->name)))->all();
    $suggestions = collect(\App\Library\Business\OnboardingServiceSuggestions::for($business?->industry))
        ->reject(fn ($name) => in_array(mb_strtolower($name), $savedNames, true))
        ->values();
    $pickedSuggestions = collect(old('suggested_services', []))->map(fn ($name) => mb_strtolower($name))->all();
    $primarySuggested = mb_strtolower((string) old('primary_suggested', ''));
    $hasSavedPrimary = $services->contains(fn ($service) => $service->is_primary);
@endphp

<h5>What do you offer?</h5>
<p class="text-muted">
    List each service as customers would search for it, for example “Photo Booth Rental”.
    Put places like “Orange County” in the previous step, not in the service name.
    Choose one primary service. Prices and details can be added later.
</p>

<form method="POST" action="{{ route('customer.onboarding.services.store') }}" data-role="services-form">
    @csrf

    @if ($suggestions->isNotEmpty())
        <fieldset class="mb-2" data-role="suggestions">
            <legend class="form-label text-label">Pick the ones that fit</legend>
            @foreach ($suggestions as $index => $name)
                <div class="d-flex flex-wrap align-items-center gap-2 mb-50">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="suggested_services[]" id="suggested-{{ $index }}" value="{{ $name }}" @checked(in_array(mb_strtolower($name), $pickedSuggestions, true))>
                        <label class="form-check-label" for="suggested-{{ $index }}">{{ $name }}</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="primary_suggested" id="primary-suggested-{{ $index }}" value="{{ $name }}" data-primary @checked($primarySuggested === mb_strtolower($name))>
                        <label class="form-check-label text-muted" for="primary-suggested-{{ $index }}">Primary</label>
                    </div>
                </div>
            @endforeach
        </fieldset>
    @endif

    <div data-role="rows">
        @foreach ($services as $index => $service)
            <x-card class="mb-1" data-row="saved">
                <input type="hidden" name="services[{{ $index }}][id]" value="{{ $service->id }}">
                <div class="row">
                    <div class="col-md-9">
                        <x-input name="services[{{ $index }}][name]" label="Service name" type="text" :value="old('services.'.$index.'.name', $service->name)" required />
                    </div>
                    <div class="col-md-3 mb-1 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="services[{{ $index }}][is_primary]" id="primary-{{ $index }}" value="1" data-primary @checked(old('services', null) === null ? $service->is_primary : old("services.$index.is_primary"))>
                            <label class="form-check-label" for="primary-{{ $index }}">Primary</label>
                        </div>
                    </div>
                </div>
            </x-card>
        @endforeach

        @php($newIndex = $services->count())
        <x-card class="mb-1" data-row="new">
            <div class="row">
                <div class="col-md-9">
                    <x-input name="services[{{ $newIndex }}][name]" label="{{ $services->isEmpty() && $suggestions->isEmpty() ? 'Service name' : 'Add another service' }}" type="text" :value="old('services.'.$newIndex.'.name')" />
                </div>
                <div class="col-md-3 mb-1 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="services[{{ $newIndex }}][is_primary]" id="primary-{{ $newIndex }}" value="1" data-primary @checked(old("services.$newIndex.is_primary"))>
                        <label class="form-check-label" for="primary-{{ $newIndex }}">Primary</label>
                    </div>
                </div>
            </div>
            <div data-controls hidden>
                <button type="button" class="btn btn-sm btn-outline-danger" data-act="del">Remove</button>
            </div>
        </x-card>
    </div>

    <div class="d-flex flex-wrap gap-1 mt-1">
        <x-button type="button" variant="outline" size="sm" data-role="add-service" hidden>Add another service</x-button>
        <x-button type="submit" variant="primary">Continue</x-button>
    </div>
    <p class="text-caption mt-1 mb-0">With a single service, it is your primary one automatically.</p>
</form>

<script>
    // Progressive enhancement: the form works without script. With it, more rows can be
    // added, unsaved rows removed, and only one "Primary" can be chosen across every row
    // and suggestion. Row names are re-indexed in page order.
    (function () {
        var form = document.querySelector('[data-role="services-form"]');
        if (!form) { return; }
        var holder = form.querySelector('[data-role="rows"]');
        var addBtn = form.querySelector('[data-role="add-service"]');
        var template = holder.querySelector('[data-row="new"]').cloneNode(true);

        function reindex() {
            Array.prototype.forEach.call(holder.querySelectorAll('[data-row]'), function (row, i) {
                row.querySelectorAll('[name^="services["]').forEach(function (f) {
                    f.name = f.name.replace(/^services\[\d+\]/, 'services[' + i + ']');
                });
                row.querySelectorAll('[id^="primary-"]').forEach(function (f) { f.id = 'primary-' + i; });
                row.querySelectorAll('label[for^="primary-"]').forEach(function (l) { l.setAttribute('for', 'primary-' + i); });
            });
        }
        form.addEventListener('change', function (e) {
            if (e.target.matches('[data-primary]') && e.target.checked) {
                form.querySelectorAll('[data-primary]').forEach(function (o) { if (o !== e.target) { o.checked = false; } });
            }
        });
        holder.addEventListener('click', function (e) {
            var b = e.target.closest('[data-act="del"]');
            if (!b) { return; }
            b.closest('[data-row]').remove();
            reindex();
        });
        addBtn.addEventListener('click', function () {
            var fresh = template.cloneNode(true);
            fresh.querySelectorAll('input[type="text"]').forEach(function (i) { i.value = ''; });
            fresh.querySelectorAll('input[type="checkbox"]').forEach(function (i) { i.checked = false; });
            fresh.querySelectorAll('[data-controls]').forEach(function (c) { c.hidden = false; });
            holder.appendChild(fresh);
            reindex();
        });
        addBtn.hidden = false;
        holder.querySelectorAll('[data-controls]').forEach(function (c) { c.hidden = false; });
        reindex();
    })();
</script>
