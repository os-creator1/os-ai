@extends('layouts/contentLayoutMaster')

@section('title', 'Edit Business Details')

@section('content')
    @php
        $labelFor = fn (string $key) => $fieldCopy[$key]['label'] ?? $key;
        $helpFor = fn (string $key) => $fieldCopy[$key]['help'] ?? null;
        $isStale = fn (string $key) => in_array($key, $completeness->staleFieldKeys ?? [], true);
        $offers = old('offers', $profile?->offers ?? []);
        $credentials = old('credentials', $profile?->credentials ?? []);
        $testimonials = old('testimonials', $profile?->testimonials ?? []);
        $selectedServiceIds = old('growth_priority_service_ids', $profile?->growth_priority_service_ids ?? []);
        $selectedLocationIds = old('growth_priority_location_ids', $profile?->growth_priority_location_ids ?? []);
    @endphp

    {{-- Correction (§7): a small, reusable "Confirm this is still correct"
         affordance for any field the completeness check flagged as stale.
         Checking it while leaving the value unchanged only refreshes that
         field's verification metadata (BusinessKnowledgeProfileManager's
         reconfirmFieldKeys parameter) -- it never fabricates a change-log
         row for a value that did not actually change. Changing the value
         itself is always a normal write regardless of this checkbox. --}}
    @php
        $reconfirmCheckbox = function (string $key) use ($isStale) {
            if (! $isStale($key)) {
                return '';
            }

            return '<div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="reconfirm[' . $key . ']" id="reconfirm-' . $key . '" value="1"><label class="form-check-label text-caption" for="reconfirm-' . $key . '">This is still accurate -- no need to re-ask</label></div>';
        };
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
                :selected="old('vertical_key', $profile?->vertical_key)"
            />
            {!! $reconfirmCheckbox('vertical_key') !!}

            <x-select
                name="pricing_method"
                :label="$labelFor('pricing_method')"
                :help="$helpFor('pricing_method')"
                :options="['' => 'Not answered', 'fixed' => 'Fixed price', 'hourly' => 'Hourly rate', 'quote_only' => 'Quote only', 'package_tiers' => 'Package tiers']"
                :selected="old('pricing_method', $profile?->pricing_method?->value)"
            />
            {!! $reconfirmCheckbox('pricing_method') !!}

            <x-select
                name="financing_available"
                label="Do you offer financing or payment plans?"
                :options="['' => 'Not answered', '1' => 'Yes', '0' => 'No']"
                :selected="old('financing_available', $profile?->financing_available === null ? '' : ($profile?->financing_available ? '1' : '0'))"
            />
            {!! $reconfirmCheckbox('financing_available') !!}

            <x-select
                name="primary_conversion_goal"
                :label="$labelFor('primary_conversion_goal')"
                :help="$helpFor('primary_conversion_goal')"
                :options="['' => 'Not answered', 'call' => 'Call us', 'quote_request' => 'Request a quote', 'consultation_booking' => 'Book a consultation', 'calendar_booking' => 'Book on a calendar', 'external_booking_link' => 'Use an external booking link']"
                :selected="old('primary_conversion_goal', $profile?->primary_conversion_goal?->value)"
            />
            {!! $reconfirmCheckbox('primary_conversion_goal') !!}

            <x-input name="conversion_target" :label="$labelFor('conversion_target')" :help="$helpFor('conversion_target')" value="{{ old('conversion_target', $profile?->conversion_target ?? '') }}" />
            {!! $reconfirmCheckbox('conversion_target') !!}

            <x-input name="years_operating" type="number" min="0" :label="$labelFor('years_operating')" :help="$helpFor('years_operating')" value="{{ old('years_operating', $profile?->years_operating ?? '') }}" />
            {!! $reconfirmCheckbox('years_operating') !!}

            <div class="ds-field mb-3">
                <label for="ideal_customers" class="form-label text-label">{{ $labelFor('ideal_customers') }}</label>
                <textarea name="ideal_customers" id="ideal_customers" rows="2" class="form-control transition-fast">{{ old('ideal_customers', $profile?->ideal_customers ?? '') }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('ideal_customers') }}</div>
                {!! $reconfirmCheckbox('ideal_customers') !!}
            </div>

            <div class="ds-field mb-3">
                <label for="warranties_guarantees" class="form-label text-label">{{ $labelFor('warranties_guarantees') }}</label>
                <textarea name="warranties_guarantees" id="warranties_guarantees" rows="2" class="form-control transition-fast">{{ old('warranties_guarantees', $profile?->warranties_guarantees ?? '') }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('warranties_guarantees') }}</div>
                {!! $reconfirmCheckbox('warranties_guarantees') !!}
            </div>

            <div class="ds-field mb-3">
                <label for="brand_voice" class="form-label text-label">{{ $labelFor('brand_voice') }}</label>
                <textarea name="brand_voice" id="brand_voice" rows="2" class="form-control transition-fast">{{ old('brand_voice', $profile?->brand_voice ?? '') }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('brand_voice') }}</div>
                {!! $reconfirmCheckbox('brand_voice') !!}
            </div>
        </x-card>

        <x-card title="Lists" class="mb-3">
            <div class="ds-field mb-3">
                <label for="differentiators" class="form-label text-label">{{ $labelFor('differentiators') }}</label>
                <textarea name="differentiators" id="differentiators" rows="3" class="form-control transition-fast">{{ old('differentiators', implode("\n", $profile?->differentiators ?? [])) }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('differentiators') }} One per line.</div>
                {!! $reconfirmCheckbox('differentiators') !!}
            </div>

            <div class="ds-field mb-3">
                <label for="customer_problems" class="form-label text-label">{{ $labelFor('customer_problems') }}</label>
                <textarea name="customer_problems" id="customer_problems" rows="3" class="form-control transition-fast">{{ old('customer_problems', implode("\n", $profile?->customer_problems ?? [])) }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('customer_problems') }} One per line.</div>
                {!! $reconfirmCheckbox('customer_problems') !!}
            </div>

            <div class="ds-field mb-3">
                <label for="prohibited_claims" class="form-label text-label">{{ $labelFor('prohibited_claims') }}</label>
                <textarea name="prohibited_claims" id="prohibited_claims" rows="3" class="form-control transition-fast">{{ old('prohibited_claims', implode("\n", $profile?->prohibited_claims ?? [])) }}</textarea>
                <div class="form-text text-caption">{{ $helpFor('prohibited_claims') }} One per line.</div>
                {!! $reconfirmCheckbox('prohibited_claims') !!}
            </div>
        </x-card>

        <x-card title="What to highlight" class="mb-3">
            {{-- Correction (§6): this hidden marker is always rendered
                 alongside the multi-select, whether or not anything is
                 selected. Its presence tells the controller "this control
                 was shown to the customer" -- a native <select multiple>
                 submits no growth_priority_service_ids key at all when
                 nothing is checked, so without this marker an
                 intentional "clear every priority" save would be
                 indistinguishable from the field never having been
                 presented at all. --}}
            <input type="hidden" name="growth_priority_service_ids_presented" value="1">
            <div class="ds-field mb-3">
                <label class="form-label text-label">{{ $labelFor('growth_priority_service_ids') }}</label>
                <select name="growth_priority_service_ids[]" multiple class="form-select transition-fast">
                    @foreach ($services as $service)
                        <option value="{{ $service->id }}" @selected(in_array($service->id, $selectedServiceIds ?? []))>{{ $service->name }}</option>
                    @endforeach
                </select>
                <div class="form-text text-caption">{{ $helpFor('growth_priority_service_ids') }}</div>
                {!! $reconfirmCheckbox('growth_priority_service_ids') !!}
            </div>

            <input type="hidden" name="growth_priority_location_ids_presented" value="1">
            <div class="ds-field mb-3">
                <label class="form-label text-label">{{ $labelFor('growth_priority_location_ids') }}</label>
                <select name="growth_priority_location_ids[]" multiple class="form-select transition-fast">
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected(in_array($location->id, $selectedLocationIds ?? []))>{{ $location->name }}</option>
                    @endforeach
                </select>
                <div class="form-text text-caption">{{ $helpFor('growth_priority_location_ids') }}</div>
                {!! $reconfirmCheckbox('growth_priority_location_ids') !!}
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
            {!! $reconfirmCheckbox('offers') !!}
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
            {!! $reconfirmCheckbox('credentials') !!}
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
            {!! $reconfirmCheckbox('testimonials') !!}
        </x-card>

        <x-button type="submit" variant="primary">Save</x-button>
    </form>

    @foreach ($locations as $location)
        <x-card :title="'Hours -- ' . $location->name" class="mt-3" id="hours-{{ $location->uid }}">
            <form method="POST" action="{{ route('customer.workspaces.businesses.knowledge-profile.locations.hours', [$workspaceUid, $businessUid, $location->uid]) }}">
                @csrf
                @method('PUT')

                {{-- Correction (§8): up to 4 periods/day are contracted
                     (§5.5) -- rendering only 2 silently dropped a stored
                     3rd/4th period on the next save. A day left entirely
                     blank means closed. For an overnight business, enter
                     the first period ending at "24:00" and a second
                     period on the SAME day row starting at "00:00" --
                     each day's hours never wrap across midnight on their
                     own. --}}
                <p class="text-caption mb-2">Leave a day's fields blank if you're closed that day. You can enter up to 4 separate open/close periods per day (e.g. a lunch break). For a business open past midnight, end one period at "24:00" and start the next period the same day at "00:00".</p>

                @php($hours = $location->hours ?? [])
                @foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day)
                    @php($periods = old($day, $hours[$day] ?? []))
                    <div class="row g-2 mb-2 align-items-center">
                        <div class="col-md-2 text-label text-capitalize">{{ $day }}</div>
                        @for ($p = 0; $p < 4; $p++)
                            <div class="col-md-1">
                                <input type="text" placeholder="09:00" name="{{ $day }}[{{ $p }}][open]" class="form-control transition-fast" value="{{ $periods[$p]['open'] ?? '' }}">
                            </div>
                            <div class="col-md-1">
                                <input type="text" placeholder="17:00" name="{{ $day }}[{{ $p }}][close]" class="form-control transition-fast" value="{{ $periods[$p]['close'] ?? '' }}">
                            </div>
                        @endfor
                    </div>
                @endforeach

                <x-input name="notes" label="Notes" help="e.g. emergency availability" value="{{ old('notes', $hours['notes'] ?? '') }}" />

                @if ($isStale('hours'))
                    <p class="text-caption mb-2">These hours haven't been confirmed recently -- saving below (even with no changes) confirms they're still correct.</p>
                @endif

                <x-button type="submit" variant="secondary">Save hours for {{ $location->name }}</x-button>
            </form>
        </x-card>
    @endforeach
@endsection
