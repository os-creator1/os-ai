@php($services = $onboarding->business?->services ?? collect())

<p>List at least one service. Choose one as your primary service.</p>

<form method="POST" action="{{ route('customer.onboarding.services.store') }}">
    @csrf

    @foreach ($services as $index => $service)
        <div class="border rounded p-1 mb-1">
            <input type="hidden" name="services[{{ $index }}][id]" value="{{ $service->id }}">
            <div class="row">
                <div class="col-md-6 mb-1">
                    <label class="form-label">Service name</label>
                    <input type="text" class="form-control" name="services[{{ $index }}][name]" value="{{ old("services.$index.name", $service->name) }}" required>
                </div>
                <div class="col-md-3 mb-1">
                    <label class="form-label">Starting price</label>
                    <input type="number" step="0.01" class="form-control" name="services[{{ $index }}][starting_price]" value="{{ old("services.$index.starting_price", $service->starting_price) }}">
                </div>
                <div class="col-md-3 mb-1 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="services[{{ $index }}][is_primary]" id="primary-{{ $index }}" value="1" @checked(old("services.$index.is_primary", $service->is_primary)) >
                        <label class="form-check-label" for="primary-{{ $index }}">Primary</label>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @php($newIndex = $services->count())
    <div class="border rounded p-1 mb-1">
        <div class="row">
            <div class="col-md-6 mb-1">
                <label class="form-label">New service name</label>
                <input type="text" class="form-control" name="services[{{ $newIndex }}][name]" value="{{ old("services.$newIndex.name") }}">
            </div>
            <div class="col-md-3 mb-1">
                <label class="form-label">Starting price</label>
                <input type="number" step="0.01" class="form-control" name="services[{{ $newIndex }}][starting_price]" value="{{ old("services.$newIndex.starting_price") }}">
            </div>
            <div class="col-md-3 mb-1 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="services[{{ $newIndex }}][is_primary]" id="primary-{{ $newIndex }}" value="1" @checked(old("services.$newIndex.is_primary"))>
                    <label class="form-check-label" for="primary-{{ $newIndex }}">Primary</label>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary mt-2">Continue</button>
</form>
