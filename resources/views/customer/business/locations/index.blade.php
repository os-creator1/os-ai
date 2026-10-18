@extends('layouts/contentLayoutMaster')

@section('title', 'Locations')

@section('page-style')
    @include('customer.settings._redesign-assets')
@endsection

@section('content')
    @php
        // Customer Experience Slice 1A — presentation only. Every figure comes
        // from EntitlementManager's LocationSlotCapacityDecision; nothing here
        // re-derives capacity, and no plan allowance is written into this page.
        // Controls a reader may never use are absent, not disabled (redesign §14.5).
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
        $showAdd = $canManage && $capacity->allowed;
    @endphp

    <div class="sr-page" style="max-width:820px">
        <a href="{{ route('customer.workspaces.businesses.settings.show', $scope) }}" class="sr-back">
            <x-ds-icon name="arrow-left" size="14" aria-hidden="true" /> Back to Settings
        </a>
        <div class="sr-head">
            <div class="sr-head__main">
                <h1 class="sr-title">Locations</h1>
                <p class="sr-lede">Your storefronts, branches and service areas. A location is part of this business — it isn't a separate account.</p>
            </div>
            @if ($showAdd)
                <x-button variant="primary" icon="plus" :href="route('customer.workspaces.businesses.locations.create', $scope)" data-role="add-location">Add a location</x-button>
            @endif
        </div>

        @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $key => $variant)
            @if (session($key))
                <x-alert :dismissible="true" :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
            @endif
        @endforeach

        @if (! $canManage)
            <p class="text-caption mb-3" data-role="read-only-note">Only the account owner or an account admin can change locations.</p>
        @endif

        <div class="sr-card" data-section="location-capacity">
            <div class="sr-card__body">
                @if ($capacity->unlimited)
                    <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                        <p class="mb-0" style="font-size:1.125rem;font-weight:700" data-role="capacity-figure">{{ $plural($capacity->activeLocationCount, 'active location') }}</p>
                        <span class="sr-chip sr-chip--success" data-role="capacity-sentence">Your plan includes unlimited locations.</span>
                    </div>
                @elseif ($capacity->effectiveCapacity !== null)
                    <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">
                        <p class="mb-0" data-role="capacity-figure">
                            <span style="font-size:1.125rem;font-weight:700">{{ $capacity->activeLocationCount }} of {{ $capacity->effectiveCapacity }} in use</span>
                            <span class="text-muted" style="font-size:.8125rem">{{ $capacity->effectiveCapacity === 1 ? 'location' : 'locations' }} included in your plan</span>
                        </p>
                        @if ($capacity->allowed)
                            <span class="sr-chip sr-chip--success" data-role="capacity-sentence"><x-ds-icon name="check" size="12" aria-hidden="true" /> You can add {{ (int) $capacity->remaining() }} more</span>
                        @endif
                    </div>

                    <div class="sr-meter" role="img" aria-label="{{ $capacity->activeLocationCount }} of {{ $capacity->effectiveCapacity }} locations in use">
                        @for ($slot = 1; $slot <= $capacity->effectiveCapacity; $slot++)
                            <span class="sr-meter__seg {{ $slot <= $capacity->activeLocationCount ? 'is-on' : '' }}"></span>
                        @endfor
                    </div>

                    <ul class="list-unstyled mt-3 mb-0 text-caption" data-role="capacity-breakdown">
                        <li>{{ $plural($capacity->includedSlots, 'location') }} included in your plan</li>
                        @if ($capacity->additionalSlotsAllocated > 0)
                            <li>{{ $plural($capacity->additionalSlotsAllocated, 'add-on location') }}</li>
                        @endif
                        @if ($capacity->grandfatheredSlots > 0)
                            <li>{{ $plural($capacity->grandfatheredSlots, 'location') }} kept free of charge because you had {{ $capacity->grandfatheredSlots === 1 ? 'it' : 'them' }} before this plan limit applied</li>
                        @endif
                    </ul>

                    @unless ($capacity->allowed)
                        @if ($capacity->denialReason === 'location_slot_allocation_required')
                            <div class="sr-callout sr-callout--warn mt-3 mb-0">
                                <x-ds-icon name="info" size="16" />
                                <p data-role="capacity-sentence">
                                    You're using every location your plan currently includes. More locations are an add-on that can't be bought online yet —
                                    contact us to add one, or archive a location you no longer use.
                                </p>
                            </div>
                        @else
                            <div class="sr-callout sr-callout--warn mt-3 mb-0">
                                <x-ds-icon name="info" size="16" />
                                <div>
                                    <p data-role="capacity-sentence">
                                        Your plan includes up to {{ $capacity->maximumSlots }} active locations per business. To run more, move to the Agency plan,
                                        which includes unlimited locations.
                                    </p>
                                    @if ($planUrl)
                                        <p class="mt-1"><a href="{{ $planUrl }}" data-role="plan-link">See your plan</a></p>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endunless
                @elseif ($capacity->allowed)
                    <p class="mb-0" data-role="capacity-sentence">You can add this business's first location.</p>
                @else
                    <p class="mb-0" data-role="capacity-sentence">New locations can't be added until this account has a plan. Your existing locations are unaffected.</p>
                @endif
            </div>
        </div>

        <div class="sr-card" data-section="active-locations">
            <div class="sr-card__head">
                <h2 class="sr-card__title">Active locations</h2>
                <span class="sr-card__meta" style="color:var(--color-primary)">{{ $plural($activeLocations->count(), 'location') }}</span>
            </div>

            @forelse ($activeLocations as $location)
                <div class="sr-list__row" style="flex-wrap:wrap;{{ $loop->last ? '' : '' }}" data-location="{{ $location->uid }}">
                    <span class="sr-tile"><x-ds-icon name="map-pin" size="18" aria-hidden="true" /></span>
                    <div class="sr-list__main">
                        <div class="sr-list__name">
                            {{ $location->name }}
                            @if ($location->is_primary)
                                <span class="sr-chip sr-chip--accent">Primary</span>
                            @endif
                        </div>
                        <div class="sr-list__sub">{{ $modeLabel($location) }} · {{ $address($location) }}</div>
                    </div>

                    @if ($canManage)
                        <div class="sr-list__actions">
                            @unless ($location->is_primary)
                                <form method="POST" action="{{ route('customer.workspaces.businesses.locations.primary', array_merge($scope, [$location->uid])) }}">
                                    @csrf
                                    <x-button type="submit" variant="secondary" size="sm" icon="star">Make primary</x-button>
                                </form>
                            @endunless
                            <x-button variant="secondary" size="sm" icon="pencil" :href="route('customer.workspaces.businesses.locations.edit', array_merge($scope, [$location->uid]))">Edit</x-button>
                        </div>

                        @if ($activeLocations->count() > 1)
                            <details class="w-100" data-role="archive-disclosure">
                                <summary class="sr-linkbtn d-inline-block" style="cursor:pointer">Archive…</summary>
                                <form method="POST" action="{{ route('customer.workspaces.businesses.locations.archive', array_merge($scope, [$location->uid])) }}" class="mt-2">
                                    @csrf
                                    <p class="text-caption mb-2">
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
                    @endif
                </div>
            @empty
                <div class="sr-card__body">
                    <x-empty-state icon="map" title="No active locations yet." description="Add the address customers visit, or the area you serve." />
                </div>
            @endforelse

            @if ($canManage && $activeLocations->count() === 1)
                <div class="sr-card__foot sr-card__foot--note" data-role="last-location-note">
                    <x-ds-icon name="lock" size="14" aria-hidden="true" /> A business keeps at least one active location, so your only one can't be archived.
                </div>
            @endif
        </div>

        @if ($archivedLocations->isNotEmpty())
            <div class="sr-card" data-section="archived-locations">
                <div class="sr-card__head">
                    <div>
                        <h2 class="sr-card__title">Archived locations</h2>
                        <p class="sr-card__sub">Archived locations use none of your plan's locations. Everything about them is kept.</p>
                    </div>
                </div>
                @foreach ($archivedLocations as $location)
                    <div class="sr-list__row" style="flex-wrap:wrap" data-location="{{ $location->uid }}">
                        <span class="sr-tile sr-tile--neutral"><x-ds-icon name="archive" size="18" aria-hidden="true" /></span>
                        <div class="sr-list__main">
                            <div class="sr-list__name">{{ $location->name }} <span class="sr-chip">Archived</span></div>
                            <div class="sr-list__sub">
                                {{ $address($location) }}
                                @if ($location->archived_at)
                                    · archived {{ $location->archived_at->format('j M Y') }}
                                @endif
                            </div>
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
            </div>
        @endif
    </div>
@endsection
