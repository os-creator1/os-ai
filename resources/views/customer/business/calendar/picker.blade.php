@extends('layouts/contentLayoutMaster')

@section('title', 'Calendar')

@section('page-style')
    @include('customer.business.calendar._styles')
@endsection

@section('content')
    @php
        // Implementation Contract 15 §12.D — presentation only.
        //
        // $locations was built ONLY from Locations LocationAccessGuard grants
        // this actor, so this list cannot disclose one they cannot reach: an
        // inaccessible Location is absent, not greyed out, and its existence is
        // not implied by a count or a "more locations" hint.
        $scopeFor = static fn ($location) => [$workspace->uid, $business->uid, $location->uid];
        $location = null;
    @endphp

    @include('customer.business.calendar._module-nav', ['active' => null])

    @if ($locations->isEmpty())
        {{-- Only reachable when the Business has no active Location at all,
             so this discloses nothing an actor could not already see. --}}
        <x-card data-section="calendar-no-locations">
            <x-empty-state icon="map-pin" title="This business has no locations yet."
                            description="Add a location to start using its calendar." />
        </x-card>
    @else
        <x-card :padded="false" data-section="calendar-location-picker">
            <div class="list-group list-group-flush">
                @foreach ($locations as $loc)
                    <a class="list-group-item list-group-item-action d-flex align-items-center justify-content-between"
                       data-role="calendar-location"
                       href="{{ route('customer.workspaces.businesses.calendar.schedule', $scopeFor($loc)) }}">
                        <span>
                            <span class="text-label">{{ $loc->name ?: 'Unnamed location' }}</span>
                            @if ($loc->is_primary)
                                <x-badge variant="accent" class="ms-50">Primary</x-badge>
                            @endif
                            @php
                                $address = collect([$loc->address_line_1, $loc->city, $loc->region])->filter()->implode(', ');
                            @endphp
                            @if ($address !== '')
                                <span class="d-block text-caption">{{ $address }}</span>
                            @endif
                        </span>
                        <x-ds-icon name="chevron-right" size="16" aria-hidden="true" />
                    </a>
                @endforeach
            </div>
        </x-card>
    @endif
@endsection
