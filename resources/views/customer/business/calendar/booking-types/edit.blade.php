@extends('layouts/contentLayoutMaster')

@section('title', 'Edit booking type')

@section('page-style')
    @include('customer.business.calendar._styles')
@endsection

@section('content')
    @php
        // Implementation Contract 15 §5.1 / §6 — the staff list below is
        // CONFIGURATION INTENT, never authorization. A nominated staff member
        // who is no longer authorized for this location is shown as such and
        // is not silently treated as bookable; the write path re-derives
        // eligibility again on every save.
        //
        // Three separate forms, never nested: the settings form (main column,
        // also submitted by the header's Save changes through form=), the
        // staff form and the status form (aside). Each posts to its own route.
        $scope = [$workspace->uid, $business->uid, $location->uid];
        $withType = array_merge($scope, [$bookingType->uid]);
        $locationName = $location->name ?: 'this location';
        $staffName = static fn ($user) => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? ('User #' . $user->id));
        $initials = static function (string $name): string {
            $parts = preg_split('/[\s@._-]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];

            return mb_strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
        };
        $configuredIds = collect($configuredStaff)->pluck('user.id')->map(static fn ($id) => (int) $id)->all();
        $eligibleAssigned = collect($configuredStaff)->where('eligible', true)->count();
        $staleStaff = collect($configuredStaff)->filter(static fn (array $row) => ! $row['eligible']);
        $publicUrl = route('public.booking.show', [$bookingType->public_booking_uuid]);
        $availabilityUrl = route('customer.workspaces.businesses.calendar.availability.index', $scope);
        $reason = (string) ($readiness['reason'] ?? '');
        $fix = match (true) {
            str_starts_with($reason, 'No one is assigned') => ['label' => 'Go to Team', 'url' => '#team'],
            str_starts_with($reason, 'No working hours') => ['label' => 'Set working hours', 'url' => $availabilityUrl],
            str_starts_with($reason, 'Inactive') => ['label' => 'Go to Status', 'url' => '#status'],
            default => null,
        };
        $notice = $bookingType->minimumNoticeMinutes();
    @endphp

    <a href="{{ route('customer.workspaces.businesses.calendar.booking-types.index', $scope) }}" class="cx-back">
        <x-ds-icon name="arrow-left" size="14" aria-hidden="true" />
        Back to booking types
    </a>

    <header class="bt-head">
        <div class="min-w-0">
            <div class="bt-head-title">
                <h1 class="cx-page-title mb-0">{{ $bookingType->name }}</h1>
                <span class="cx-pill has-dot {{ $bookingType->is_active ? 'is-success' : '' }}">{{ $bookingType->is_active ? 'Active' : 'Inactive' }}</span>
            </div>
            <p class="cx-page-sub mt-25">At <strong>{{ $locationName }}</strong> · {{ $bookingType->duration_minutes }} min</p>
        </div>
        <x-button type="submit" variant="primary" icon="check" form="booking-type-form">Save changes</x-button>
    </header>

    @if (! $readiness['ready'])
        <div class="cx-callout is-warning mb-2" data-role="booking-type-not-ready">
            <x-ds-icon name="alert-circle" size="16" aria-hidden="true" />
            <span class="cx-callout-text"><strong>Customers cannot book this yet.</strong> {{ $reason }}</span>
            @if ($fix)
                <x-button variant="secondary" size="sm" :href="$fix['url']">{{ $fix['label'] }}</x-button>
            @endif
        </div>
    @endif

    @if ($errors->any())
        <div class="cx-callout is-danger mb-2" role="alert">
            <x-ds-icon name="alert-circle" size="16" aria-hidden="true" />
            <span class="cx-callout-text">Nothing was saved. Check the highlighted fields below.</span>
        </div>
    @endif

    <div class="cx-split">
        <form method="POST" id="booking-type-form" class="cx-split-main"
              action="{{ route('customer.workspaces.businesses.calendar.booking-types.update', $withType) }}">
            @csrf
            @include('customer.business.calendar.booking-types._settings-fields', ['bookingType' => $bookingType])
            @include('customer.business.calendar.booking-types._notification-fields', ['bookingType' => $bookingType, 'notificationReadiness' => $notificationReadiness])
            <x-button type="submit" variant="primary" icon="check" class="mb-2">Save changes</x-button>
        </form>

        <aside class="cx-split-aside">
            <section class="cx-card" id="team" data-section="booking-type-team">
                <div class="cx-card-head is-plain">
                    <h2 class="cx-card-title">Team</h2>
                    @if ($eligibleAssigned === 0)
                        <span class="cx-pill is-warning">No one assigned</span>
                    @else
                        <span class="cx-pill is-success">{{ $eligibleAssigned }} assigned</span>
                    @endif
                </div>
                <div class="cx-card-body">
                    <p class="text-caption mb-1">
                        Who offers this. <strong>Availability comes from assigned staff working hours.</strong>
                    </p>

                    @if ($configuredStaff === [])
                        <div class="cx-callout is-warning mb-1" data-role="no-staff-warning">
                            <span class="cx-callout-text">No one is assigned, so customers cannot book this yet. Tick at least one person below and save.</span>
                        </div>
                    @else
                        <p class="cx-hint mt-0 mb-1" data-role="assigned-staff">
                            Assigned: {{ collect($configuredStaff)->map(fn ($row) => $staffName($row['user']))->implode(', ') }}
                        </p>
                    @endif

                    @if ($staleStaff->isNotEmpty())
                        <div class="cx-callout is-warning mb-1">
                            <span class="cx-callout-text">
                                {{ $staleStaff->count() }} person(s) listed here are no longer authorized for this location and
                                cannot be scheduled. Save staff to clear them.
                            </span>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.booking-types.staff', $withType) }}">
                        @csrf
                        <input type="hidden" name="staff_user_ids[]" value="">

                        @if ($eligibleStaff->isEmpty())
                            <p class="text-caption">Nobody is currently authorized for this location.</p>
                        @else
                            <ul class="bt-staff">
                                @foreach ($eligibleStaff as $candidate)
                                    <li>
                                        <label for="staff-{{ $candidate->id }}">
                                            <input type="checkbox" id="staff-{{ $candidate->id }}" name="staff_user_ids[]" value="{{ $candidate->id }}"
                                                   @checked(in_array((int) $candidate->id, $configuredIds, true))>
                                            <span class="cx-avatar is-sm" aria-hidden="true">{{ $initials($staffName($candidate)) }}</span>
                                            <span class="bt-staff-name">{{ $staffName($candidate) }}</span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- Shown whenever there is something to save or to clear: with nobody eligible left,
                             saving submits only the empty placeholder above and removes the stale names. --}}
                        @if ($eligibleStaff->isNotEmpty() || $configuredStaff !== [])
                            <x-button type="submit" variant="primary" icon="check" class="w-100 justify-content-center" data-role="save-staff">Save staff</x-button>
                        @endif
                    </form>

                    <a href="{{ $availabilityUrl }}" class="d-inline-flex align-items-center gap-1 mt-1 text-label" data-role="view-staff-availability">
                        View staff availability
                        <x-ds-icon name="arrow-right" size="13" aria-hidden="true" />
                    </a>
                    <p class="cx-hint">
                        Only people currently authorized for this location can be added. Removing someone's access elsewhere
                        takes effect immediately — a name left here never restores it.
                    </p>
                </div>
            </section>

            <section class="cx-card" data-section="public-booking-page">
                <div class="cx-card-head is-plain"><h2 class="cx-card-title">Public booking page</h2></div>
                <div class="cx-card-body">
                    @if ($readiness['ready'])
                        <div class="bt-link-row mb-1">
                            <span class="bt-link-url">
                                <x-ds-icon name="link" size="14" aria-hidden="true" />
                                <span title="{{ $publicUrl }}">{{ $publicUrl }}</span>
                            </span>
                        </div>
                        <div class="bt-link-actions mb-1">
                            <x-button type="button" variant="outline" size="sm" icon="copy" data-role="copy-public-link"
                                      data-copy-link="{{ $publicUrl }}" aria-label="Copy public booking link for {{ $bookingType->name }}">Copy link</x-button>
                            <x-button variant="secondary" size="sm" icon="external-link" :href="$publicUrl" target="_blank" rel="noopener"
                                      data-role="open-public-page">Open booking page</x-button>
                            <span class="text-caption" data-copy-feedback hidden role="status"></span>
                        </div>
                    @else
                        <div class="bt-link-row mb-1" data-role="public-page-not-ready">
                            <span class="bt-link-url">
                                <x-ds-icon name="link-2-off" size="14" aria-hidden="true" />
                                <span>Link appears once customers can book this.</span>
                            </span>
                        </div>
                    @endif
                    <p class="cx-hint mb-0" data-role="booking-rules-summary">
                        Customers book {{ $notice > 0 ? 'with at least '.($notice % 60 === 0 ? ($notice / 60).' hour(s)' : $notice.' minutes').' notice' : 'with no minimum notice' }},
                        up to {{ $bookingType->windowDays() }} days ahead, starting every {{ $bookingType->slotIntervalMinutes() }} minutes
                        inside each person's working hours. Times are shown in the customer's time zone.
                    </p>
                </div>
            </section>

            <section class="cx-card" id="status">
                <div class="cx-card-head is-plain">
                    <h2 class="cx-card-title">Status</h2>
                    <span class="cx-pill has-dot {{ $bookingType->is_active ? 'is-success' : '' }}">{{ $bookingType->is_active ? 'Active' : 'Inactive' }}</span>
                </div>
                <div class="cx-card-body">
                    <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.booking-types.active', $withType) }}">
                        @csrf
                        <input type="hidden" name="is_active" value="{{ $bookingType->is_active ? 0 : 1 }}">
                        <p class="text-caption mb-1">
                            This booking type is currently {{ $bookingType->is_active ? 'active' : 'inactive' }}.
                            {{ $bookingType->is_active ? 'Deactivating it stops new bookings from the public page.' : 'Activate it to accept bookings again.' }}
                        </p>
                        <x-button type="submit" variant="secondary" size="sm" class="w-100 justify-content-center">
                            {{ $bookingType->is_active ? 'Deactivate' : 'Activate' }}
                        </x-button>
                    </form>
                </div>
            </section>
        </aside>
    </div>
@endsection

@section('page-script')
    @include('customer.business.calendar.booking-types._copy-link')
    @include('customer.business.calendar.booking-types._editor-script')
@endsection
