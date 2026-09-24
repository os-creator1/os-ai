@extends('layouts/contentLayoutMaster')

@section('title', 'Calendar')

@section('content')
    @php
        // Implementation Contract 15 §12.D — presentation only.
        //
        // $locations was built ONLY from Locations LocationAccessGuard grants
        // this actor, so this list cannot disclose one they cannot reach: an
        // inaccessible Location is absent, not greyed out, and its existence is
        // not implied by a count or a "more locations" hint.
        $scopeFor = static fn ($location) => [$workspace->uid, $business->uid, $location->uid];
    @endphp

    <section class="mb-2">
        <h1 class="h3 mb-25">Calendar</h1>
        <p class="text-body mb-0">Choose a location to see its schedule. Each location has its own calendar.</p>
    </section>

    @if ($locations->isEmpty())
        {{-- Only reachable when the Business has no active Location at all,
             so this discloses nothing an actor could not already see. --}}
        <div class="card" data-section="calendar-no-locations">
            <div class="card-body">
                <p class="mb-0">This business has no locations yet. Add a location to start using its calendar.</p>
            </div>
        </div>
    @else
    <div class="card" data-section="calendar-location-picker">
        <div class="list-group list-group-flush">
            @foreach ($locations as $location)
                <a class="list-group-item list-group-item-action"
                   data-role="calendar-location"
                   href="{{ route('customer.workspaces.businesses.calendar.schedule', $scopeFor($location)) }}">
                    <strong>{{ $location->name ?: 'Unnamed location' }}</strong>
                    @if ($location->is_primary)
                        <span class="badge badge-light-primary ml-50">Primary</span>
                    @endif
                    @php
                        $address = collect([$location->address_line_1, $location->city, $location->region])->filter()->implode(', ');
                    @endphp
                    @if ($address !== '')
                        <div class="text-muted small">{{ $address }}</div>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
    @endif
@endsection
