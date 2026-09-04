@php($services = $onboarding->business?->services ?? collect())

<p>List at least one service. Choose one as your primary service.</p>

<form method="POST" action="{{ route('customer.onboarding.services.store') }}">
    @csrf

    @foreach ($services as $index => $service)
        <x-card class="mb-1">
            <input type="hidden" name="services[{{ $index }}][id]" value="{{ $service->id }}">
            <div class="row">
                <div class="col-md-6">
                    <x-input name="services[{{ $index }}][name]" label="Service name" type="text" value="{{ old("services.$index.name", $service->name) }}" required />
                </div>
                <div class="col-md-3">
                    <x-input name="services[{{ $index }}][starting_price]" label="Starting price" type="number" step="0.01" value="{{ old("services.$index.starting_price", $service->starting_price) }}" />
                </div>
                <div class="col-md-3 mb-1 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="services[{{ $index }}][is_primary]" id="primary-{{ $index }}" value="1" @checked(old("services.$index.is_primary", $service->is_primary)) >
                        <label class="form-check-label" for="primary-{{ $index }}">Primary</label>
                    </div>
                </div>
            </div>
        </x-card>
    @endforeach

    @php($newIndex = $services->count())
    <x-card class="mb-1">
        <div class="row">
            <div class="col-md-6">
                <x-input name="services[{{ $newIndex }}][name]" label="New service name" type="text" value="{{ old("services.$newIndex.name") }}" />
            </div>
            <div class="col-md-3">
                <x-input name="services[{{ $newIndex }}][starting_price]" label="Starting price" type="number" step="0.01" value="{{ old("services.$newIndex.starting_price") }}" />
            </div>
            <div class="col-md-3 mb-1 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="services[{{ $newIndex }}][is_primary]" id="primary-{{ $newIndex }}" value="1" @checked(old("services.$newIndex.is_primary"))>
                    <label class="form-check-label" for="primary-{{ $newIndex }}">Primary</label>
                </div>
            </div>
        </div>
    </x-card>

    <x-button type="submit" variant="primary" class="mt-2">Continue</x-button>
</form>
