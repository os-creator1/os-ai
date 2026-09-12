@extends('layouts/contentLayoutMaster')

@section('title', 'Locations')

@section('content')
    @php
        // Customer Experience Slice 1A — presentation only. Every figure comes
        // from EntitlementManager's LocationSlotCapacityDecision; nothing here
        // re-derives capacity. Controls a reader may never use are absent,
        // not disabled (redesign §14.5).
        $scope = [$workspace->uid, $business->uid];
        $plural = static fn (int $count, string $word) => $count . ' ' . $word . ($count === 1 ? '' : 's');
        $address = static function ($location): string {
            $parts = array_filter([
                $location->address_line_1,
                $location->city,
                $location->region,
                $location->postal_code,
                $location->country_code,
            ], static fn ($part) => $part !== null && $part !== '');

            return $parts === [] ? 'No address on file' : implode(', ', $parts);
        };
        $modeLabel = static fn ($location): string => match ($location->service_mode?->value) {
            'storefront' => 'Storefront',
            'service_area' => 'Service area',
            'hybrid' => 'Storefront and service area',
            'online' => 'Online only',
            default => 'Location',
        };
        $otherActive = static fn ($location) => $activeLocations->filter(fn ($other) => $other->id !== $location->id)->values();
    @endphp

    <section class="mb-2" aria-labelledby="locations-heading">
        <h2 class="h4 text-section-heading mb-50" id="locations-heading">Locations of {{ $business->name }}</h2>
        <p class="text-body mb-0">
            Your storefronts, branches and service areas. A location is part of this business — it isn't a separate account.
        </p>
    </section>

    @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $key => $variant)
        @if (session($key))
            <x-alert :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
        @endif
    @endforeach

    <x-card title="Location capacity" class="mb-2" data-section="location-capacity">
        @if ($capacity->unlimited)
            <p class="h3 mb-25" data-role="capacity-figure">{{ $plural($capacity->activeLocationCount, 'active location') }}</p>
            <p class="mb-0" data-role="capacity-sentence">Your plan includes unlimited locations.</p>
        @elseif ($capacity->effectiveCapacity !== null)
            <p class="h3 mb-25" data-role="capacity-figure">
                {{ $capacity->activeLocationCount }} of {{ $capacity->effectiveCapacity }} active {{ $capacity->effectiveCapacity === 1 ? 'location' : 'locations' }} in use
            </p>
            <ul class="list-unstyled mb-1" data-role="capacity-breakdown">
                <li>{{ $plural($capacity->includedSlots, 'location') }} included in your plan</li>
                @if ($capacity->additionalSlotsAllocated > 0)
                    <li>{{ $plural($capacity->additionalSlotsAllocated, 'add-on location') }}</li>
                @endif
                @if ($capacity->grandfatheredSlots > 0)
                    <li>{{ $plural($capacity->grandfatheredSlots, 'location') }} kept free of charge because you had {{ $capacity->grandfatheredSlots === 1 ? 'it' : 'them' }} before this plan limit applied</li>
                @endif
            </ul>

            @if ($capacity->allowed)
                <p class="mb-0" data-role="capacity-sentence">You can add {{ $plural((int) $capacity->remaining(), 'more location') }}.</p>
            @elseif ($capacity->denialReason === 'location_slot_allocation_required')
                <p class="mb-0" data-role="capacity-sentence">
                    You're using every location your plan currently includes. More locations are an add-on that can't be bought online yet —
                    contact us to add one, or archive a location you no longer use.
                </p>
            @else
                <p class="mb-0" data-role="capacity-sentence">
                    Your plan includes up to {{ $capacity->maximumSlots }} active locations per business. To run more, move to the Agency plan,
                    which includes unlimited locations.
                </p>
                @if ($planUrl)
                    <p class="mt-1 mb-0"><a href="{{ $planUrl }}" data-role="plan-link">See your plan</a></p>
                @endif
            @endif
        @elseif ($capacity->allowed)
            <p class="mb-0" data-role="capacity-sentence">You can add this business's first location.</p>
        @else
            <p class="mb-0" data-role="capacity-sentence">New locations can't be added until this account has a plan. Your existing locations are unaffected.</p>
        @endif
    </x-card>

    @if ($canManage && $capacity->allowed)
        <div class="mb-2">
            <x-button variant="primary" icon="plus" :href="route('customer.workspaces.businesses.locations.create', $scope)" data-role="add-location">Add a location</x-button>
        </div>
    @elseif (! $canManage)
        <p class="text-caption mb-2" data-role="read-only-note">Only the account owner or an account admin can change locations.</p>
    @endif

    <x-card title="Active locations" class="mb-2" data-section="active-locations">
        @forelse ($activeLocations as $location)
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start py-1 {{ $loop->last ? '' : 'border-bottom' }}" data-location="{{ $location->uid }}">
                <div class="mb-1 mb-md-0">
                    <p class="text-label mb-25">
                        {{ $location->name }}
                        @if ($location->is_primary)
                            <x-badge variant="accent" class="ms-50">Primary</x-badge>
                        @endif
                    </p>
                    <p class="text-caption mb-0">{{ $modeLabel($location) }} · {{ $address($location) }}</p>
                </div>

                @if ($canManage)
                    <div class="d-flex flex-wrap gap-1">
                        <x-button variant="secondary" size="sm" icon="pencil" :href="route('customer.workspaces.businesses.locations.edit', array_merge($scope, [$location->uid]))">Edit</x-button>

                        @unless ($location->is_primary)
                            <form method="POST" action="{{ route('customer.workspaces.businesses.locations.primary', array_merge($scope, [$location->uid])) }}">
                                @csrf
                                <x-button type="submit" variant="secondary" size="sm" icon="star">Make primary</x-button>
                            </form>
                        @endunless

                        @if ($activeLocations->count() > 1)
                            <details class="w-100" data-role="archive-disclosure">
                                <summary class="text-label">Archive…</summary>
                                <form method="POST" action="{{ route('customer.workspaces.businesses.locations.archive', array_merge($scope, [$location->uid])) }}" class="mt-1">
                                    @csrf
                                    <p class="text-caption mb-1">
                                        Archiving takes {{ $location->name }} out of use and frees one location. Its details, history and any Google
                                        listing link are kept, and you can reactivate it later.
                                        @if ($capacity->grandfatheredSlots > 0 && $capacity->isOverCapacity())
                                            Because this business has more locations than its plan includes, archiving one doesn't make room for a new location.
                                        @endif
                                    </p>
                                    @if ($location->is_primary)
                                        <x-select
                                            name="new_primary_location_uid"
                                            label="New primary location"
                                            :options="$otherActive($location)->mapWithKeys(fn ($other) => [$other->uid => $other->name])->all()"
                                            required
                                        />
                                    @endif
                                    <x-button type="submit" variant="danger" size="sm" icon="archive">Archive {{ $location->name }}</x-button>
                                </form>
                            </details>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <x-empty-state icon="map" title="No active locations yet." description="Add the address customers visit, or the area you serve." />
        @endforelse

        @if ($canManage && $activeLocations->count() === 1)
            <p class="text-caption mt-1 mb-0" data-role="last-location-note">A business keeps at least one active location, so your only one can't be archived.</p>
        @endif
    </x-card>

    @if ($archivedLocations->isNotEmpty())
        <x-card title="Archived locations" class="mb-2" data-section="archived-locations">
            <p class="text-caption">Archived locations use none of your plan's locations. Everything about them is kept.</p>
            @foreach ($archivedLocations as $location)
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center py-1 {{ $loop->last ? '' : 'border-bottom' }}" data-location="{{ $location->uid }}">
                    <div class="mb-1 mb-md-0">
                        <p class="text-label mb-25">{{ $location->name }} <x-badge variant="neutral" class="ms-50">Archived</x-badge></p>
                        <p class="text-caption mb-0">
                            {{ $address($location) }}
                            @if ($location->archived_at)
                                · archived {{ $location->archived_at->format('j M Y') }}
                            @endif
                        </p>
                    </div>

                    @if ($canManage)
                        @if ($capacity->allowed)
                            <form method="POST" action="{{ route('customer.workspaces.businesses.locations.reactivate', array_merge($scope, [$location->uid])) }}">
                                @csrf
                                <x-button type="submit" variant="secondary" size="sm" icon="rotate-ccw">Reactivate</x-button>
                            </form>
                        @else
                            <p class="text-caption mb-0" data-role="reactivate-blocked">To reactivate this location, archive another one first or add capacity.</p>
                        @endif
                    @endif
                </div>
            @endforeach
        </x-card>
    @endif
@endsection
