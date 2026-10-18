@extends(request()->query('fragment') === '1' ? 'customer.business.calendar._fragment' : 'customer.business.calendar._frame')

@section('title', 'Booking types')
@section('calendar-active', 'booking-types')

@section('calendar-section')
    @php
        // Implementation Contract 15 §5.1 / §12.B — presentation only. Every
        // authorization answer was decided before this view rendered; nothing
        // here re-derives one, and no control is shown that the write path
        // would refuse. A public link is printed only when the manager's own
        // readiness check says the public page would actually answer.
        $scope = [$workspace->uid, $business->uid, $location->uid];
        // The stored colour is free text; only a plain hex or colour keyword becomes a CSS value.
        $accent = static fn ($color) => is_string($color) && preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,20})$/', $color) ? $color : null;
    @endphp

    <div data-section="booking-types-list">
        <p class="bt-intro">
            What customers can book at <strong>{{ $location->name ?: 'this location' }}</strong>, and how long each takes.
            Booking types belong to one location and are never shared between locations.
        </p>

        @if ($bookingTypes->isEmpty())
            <div class="cx-card">
                <div class="cx-card-body">
                    <x-empty-state icon="tag" title="No booking types yet." description="Add one to describe what can be booked here.">
                        <x-slot name="action">
                            <x-button variant="primary" icon="plus" :href="route('customer.workspaces.businesses.calendar.booking-types.create', $scope)">Add booking type</x-button>
                        </x-slot>
                    </x-empty-state>
                </div>
            </div>
        @else
            <div data-role="booking-types-table">
                @foreach ($bookingTypes as $bookingType)
                    @php
                        $state = $readiness[$bookingType->id] ?? ['ready' => false, 'reason' => null];
                        $editUrl = route('customer.workspaces.businesses.calendar.booking-types.edit', array_merge($scope, [$bookingType->uid]));
                        $publicUrl = route('public.booking.show', [$bookingType->public_booking_uuid]);
                        $reason = (string) ($state['reason'] ?? '');
                        // The readiness reason names the missing step; point at the page that fixes it.
                        $fix = match (true) {
                            str_starts_with($reason, 'No one is assigned') => ['label' => 'Assign staff', 'url' => $editUrl.'#team'],
                            str_starts_with($reason, 'No working hours') => ['label' => 'Set working hours', 'url' => route('customer.workspaces.businesses.calendar.availability.index', $scope)],
                            str_starts_with($reason, 'Invalid scheduling') => ['label' => 'Fix settings', 'url' => $editUrl],
                            default => null,
                        };
                    @endphp
                    <article class="cx-card bt-card" data-booking-type="{{ $bookingType->uid }}"
                             @if ($accent($bookingType->color)) style="--bt-accent: {{ $accent($bookingType->color) }}" @endif>
                        <div class="bt-card-top">
                            <div class="min-w-0">
                                <h2 class="bt-card-name">{{ $bookingType->name }}</h2>
                                <div class="bt-card-facts">
                                    <span class="cx-pill has-dot {{ $bookingType->is_active ? 'is-success' : '' }}">{{ $bookingType->is_active ? 'Active' : 'Inactive' }}</span>
                                    <span><x-ds-icon name="clock" size="13" aria-hidden="true" />{{ $bookingType->duration_minutes }} min</span>
                                </div>
                            </div>
                            <x-button variant="secondary" size="sm" icon="pencil" :href="$editUrl">Edit</x-button>
                        </div>

                        @if ($bookingType->is_active && ! $state['ready'])
                            <div class="bt-card-bottom">
                                <div class="cx-callout is-warning" data-role="booking-link-unavailable">
                                    <x-ds-icon name="alert-circle" size="16" aria-hidden="true" />
                                    <span class="cx-callout-text"><strong>Not bookable yet.</strong> {{ $reason }}</span>
                                    @if ($fix)
                                        <x-button variant="secondary" size="sm" :href="$fix['url']">{{ $fix['label'] }}</x-button>
                                    @endif
                                </div>
                            </div>
                        @elseif ($bookingType->is_active)
                            <div class="bt-card-bottom">
                                <div class="bt-link-row">
                                    <span class="bt-link-url">
                                        <x-ds-icon name="link" size="14" aria-hidden="true" />
                                        <span title="{{ $publicUrl }}">{{ $publicUrl }}</span>
                                    </span>
                                    <span class="bt-link-actions">
                                        <span class="text-caption" data-copy-feedback hidden role="status"></span>
                                        <x-button type="button" variant="outline" size="sm" icon="copy" data-role="copy-public-link"
                                                  data-copy-link="{{ $publicUrl }}" aria-label="Copy booking link for {{ $bookingType->name }}">Copy link</x-button>
                                        <x-button variant="secondary" size="sm" icon="external-link" :href="$publicUrl" target="_blank" rel="noopener"
                                                  data-role="open-public-page">Open booking page</x-button>
                                    </span>
                                </div>
                            </div>
                        @else
                            <div class="bt-card-bottom">
                                <p class="text-caption mb-0">Inactive, so customers cannot book it. Open it to activate.</p>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>
@endsection
