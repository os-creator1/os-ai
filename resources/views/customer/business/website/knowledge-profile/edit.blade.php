@extends('layouts/contentLayoutMaster')

@section('title', 'Edit Business Details')

@section('content')
    @php
        $labelFor = fn (string $key) => $fieldCopy[$key]['label'] ?? $key;
        $helpFor = fn (string $key) => $fieldCopy[$key]['help'] ?? null;
        $offers = old('offers', $profile->offers ?? []);
        $credentials = old('credentials', $profile->credentials ?? []);
        $testimonials = old('testimonials', $profile->testimonials ?? []);
        $selectedServiceIds = old('growth_priority_service_ids', $profile->growth_priority_service_ids ?? []);
        $selectedLocationIds = old('growth_priority_location_ids', $profile->growth_priority_location_ids ?? []);
    @endphp

    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Edit Business Details</h4>
            <p class="text-caption mb-0">Answer what you can -- you can always come back and finish the rest later.</p>
        </div>
    </div>

    @if ($errors->any())
        <x-alert variant="danger" class="mb-3">
            <p class="fw-medium mb-1">Please fix the following:</p>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <form method="POST" action="{{ route('customer.workspaces.businesses.knowledge-profile.update', [$workspaceUid, $businessUid]) }}">
        @csrf
        @method('PUT')

        <x-card title="About your business" class="mb-3">
            <x-select
                name="vertical_key"
                :label="$labelFor('vertical_key')"
                :help="$helpFor('vertical_key')"
                :options="['' => 'Not applicable'] + $verticals->pluck('display_name', 'key')->all()"
                :selected="old('vertical_key', $profile->vertical_key)"
            />

            <x-select
                name="pricing_method"
                :label="$labelFor('pricing_method')"
                :help="$helpFor('pricing_method')"
                :options="['' => 'Not answered', 'fixed' => 'Fixed price', 'hourly' => 'Hourly rate', 'quote_only' => 'Quote only', 'package_tiers' => 'Package tiers']"
                :selected="old('pricing_method', $profile->pricing_method?->value)"
            />

            <x-select
                name="financing_available"
                label="Do you offer financing or payment plans?"
                :options="['' => 'Not answered', '1' => 'Yes', '0' => 'No']"
                :selected="old('financing_available', $profile?->financing_available === null ? '' : ($profile->financing_available ? '1' : '0'))"
            />

            <x-select
                name="primary_conversion_goal"
                :label="$labelFor('primary_conversion_goal')"
                :help="$helpFor('primary_conversion_goal')"
                :options="['' => 'Not answered', 'call' => 'Call us', 'quote_request' => 'Request a quote', 'consultation_booking' => 'Book a consultation', 'calendar_booking' => 'Book on a calendar', 'external_booking_link' => 'Use an external booking link']"
                :selected="old('primary_conversion_goal', $profile->primary_conversion_goal?->value)"
            />

            <x-input name="conversion_target" :label="$labelFor('conversion_target')" :help="$helpFor('conversion_target')" value="{{ old('conversion_target', $profile->conversion_target ?? '') }}" />

            <x-input name="years_operating" type="number" min="0" :label="$labelFor('years_operating')" :help="$helpFor('years_operating')" value="{{ old('years_operating', $profile->years_operating ?? '') }}" />

            <div class="ds-field mb-3">
                <label for="ideal_customers" class="form-label text-label">{{ $labelFor('ideal_customers') }}</label>
                <textarea name="ideal_customers" id="ideal_customers" rows="2" class="form-control transition-fast">{{ old('ideal_customers', $profile->ideal_customers ?? '') }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('ideal_customers') }}</div>
            </div>

            <div class="ds-field mb-3">
                <label for="warranties_guarantees" class="form-label text-label">{{ $labelFor('warranties_guarantees') }}</label>
                <textarea name="warranties_guarantees" id="warranties_guarantees" rows="2" class="form-control transition-fast">{{ old('warranties_guarantees', $profile->warranties_guarantees ?? '') }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('warranties_guarantees') }}</div>
            </div>

            <div class="ds-field mb-3">
                <label for="brand_voice" class="form-label text-label">{{ $labelFor('brand_voice') }}</label>
                <textarea name="brand_voice" id="brand_voice" rows="2" class="form-control transition-fast">{{ old('brand_voice', $profile->brand_voice ?? '') }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('brand_voice') }}</div>
            </div>
        </x-card>

        <x-card title="Lists" class="mb-3">
            <div class="ds-field mb-3">
                <label for="differentiators" class="form-label text-label">{{ $labelFor('differentiators') }}</label>
                <textarea name="differentiators" id="differentiators" rows="3" class="form-control transition-fast">{{ old('differentiators', implode("\n", $profile->differentiators ?? [])) }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('differentiators') }} One per line.</div>
            </div>

            <div class="ds-field mb-3">
                <label for="customer_problems" class="form-label text-label">{{ $labelFor('customer_problems') }}</label>
                <textarea name="customer_problems" id="customer_problems" rows="3" class="form-control transition-fast">{{ old('customer_problems', implode("\n", $profile->customer_problems ?? [])) }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('customer_problems') }} One per line.</div>
            </div>

            <div class="ds-field mb-3">
                <label for="prohibited_claims" class="form-label text-label">{{ $labelFor('prohibited_claims') }}</label>
                <textarea name="prohibited_claims" id="prohibited_claims" rows="3" class="form-control transition-fast">{{ old('prohibited_claims', implode("\n", $profile->prohibited_claims ?? [])) }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('prohibited_claims') }} One per line.</div>
            </div>
        </x-card>

        <x-card title="What to highlight" class="mb-3">
            <div class="ds-field mb-3">
                <label class="form-label text-label">{{ $labelFor('growth_priority_service_ids') }}</label>
                <select name="growth_priority_service_ids[]" multiple class="form-select transition-fast">
                    @foreach ($services as $service)
                        <option value="{{ $service->id }}" @selected(in_array($service->id, $selectedServiceIds ?? []))>{{ $service->name }}</option>
                    @endforeach
                </select>
                <div class="form-text text-caption">{{ $helpFor('growth_priority_service_ids') }}</div>
            </div>

            <div class="ds-field mb-3">
                <label class="form-label text-label">{{ $labelFor('growth_priority_location_ids') }}</label>
                <select name="growth_priority_location_ids[]" multiple class="form-select transition-fast">
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected(in_array($location->id, $selectedLocationIds ?? []))>{{ $location->name }}</option>
                    @endforeach
                </select>
                <div class="form-text text-caption">{{ $helpFor('growth_priority_location_ids') }}</div>
            </div>
        </x-card>

        <x-card title="Offers or packages" class="mb-3">
            <p class="text-caption mb-3">{{ $helpFor('offers') }}</p>
            @for ($i = 0; $i < max(count($offers), 1) + 2 && $i < 12; $i++)
                <div class="row g-2 mb-2 align-items-end">
                    <div class="col-md-3"><x-input :name="\"offers[{$i}][name]\"" label="Name" :value="$offers[$i]['name'] ?? ''" /></div>
                    <div class="col-md-4"><x-input :name="\"offers[{$i}][description]\"" label="Description" :value="$offers[$i]['description'] ?? ''" /></div>
                    <div class="col-md-2"><x-input :name="\"offers[{$i}][price_label]\"" label="Price label" :value="$offers[$i]['price_label'] ?? ''" /></div>
                    <div class="col-md-3">
                        <x-select :name="\"offers[{$i}][pricing_method_override]\"" label="Pricing override" :options="['' => 'Use general pricing', 'fixed' => 'Fixed price', 'hourly' => 'Hourly rate', 'quote_only' => 'Quote only', 'package_tiers' => 'Package tiers']" :selected="$offers[$i]['pricing_method_override'] ?? ''" />
                    </div>
                </div>
            @endfor
        </x-card>

        <x-card title="Licenses, certifications, or insurance" class="mb-3">
            @for ($i = 0; $i < max(count($credentials), 1) + 2 && $i < 10; $i++)
                <div class="row g-2 mb-2 align-items-end">
                    <div class="col-md-8"><x-input :name="\"credentials[{$i}][label]\"" label="Label" :value="$credentials[$i]['label'] ?? ''" /></div>
                    <div class="col-md-4">
                        <div class="form-check mt-4">
                            <input type="checkbox" class="form-check-input" name="credentials[{{ $i }}][verified]" id="credentials-{{ $i }}-verified" value="1" @checked((bool) ($credentials[$i]['verified'] ?? false))>
                            <label class="form-check-label text-caption" for="credentials-{{ $i }}-verified">Verified</label>
                        </div>
                    </div>
                </div>
            @endfor
        </x-card>

        <x-card title="Customer testimonials" class="mb-3">
            <p class="text-caption mb-3">{{ $helpFor('testimonials') }}</p>
            @for ($i = 0; $i < max(count($testimonials), 1) + 2 && $i < 5; $i++)
                <div class="row g-2 mb-2 align-items-end">
                    <div class="col-md-6"><x-input :name="\"testimonials[{$i}][quote]\"" label="Quote" :value="$testimonials[$i]['quote'] ?? ''" /></div>
                    <div class="col-md-3"><x-input :name="\"testimonials[{$i}][author_name]\"" label="Author" :value="$testimonials[$i]['author_name'] ?? ''" /></div>
                    <div class="col-md-3"><x-input :name="\"testimonials[{$i}][author_title]\"" label="Author title" :value="$testimonials[$i]['author_title'] ?? ''" /></div>
                </div>
            @endfor
        </x-card>

        <x-button type="submit" variant="primary">Save</x-button>
    </form>

    @foreach ($locations as $location)
        <x-card :title="'Hours -- ' . $location->name" class="mt-3" id="hours-{{ $location->uid }}">
            <form method="POST" action="{{ route('customer.workspaces.businesses.knowledge-profile.locations.hours', [$workspaceUid, $businessUid, $location->uid]) }}">
                @csrf
                @method('PUT')

                @php($hours = $location->hours ?? [])
                @foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day)
                    @php($periods = $hours[$day] ?? [])
                    <div class="row g-2 mb-2 align-items-center">
                        <div class="col-md-2 text-label text-capitalize">{{ $day }}</div>
                        @for ($p = 0; $p < 2; $p++)
                            <div class="col-md-2">
                                <input type="text" placeholder="09:00" name="{{ $day }}[{{ $p }}][open]" class="form-control transition-fast" value="{{ $periods[$p]['open'] ?? '' }}">
                            </div>
                            <div class="col-md-2">
                                <input type="text" placeholder="17:00 or 24:00" name="{{ $day }}[{{ $p }}][close]" class="form-control transition-fast" value="{{ $periods[$p]['close'] ?? '' }}">
                            </div>
                        @endfor
                    </div>
                @endforeach

                <x-input name="notes" label="Notes" help="e.g. emergency availability" value="{{ $hours['notes'] ?? '' }}" />

                <x-button type="submit" variant="secondary">Save hours for {{ $location->name }}</x-button>
            </form>
        </x-card>
    @endforeach
@endsection
