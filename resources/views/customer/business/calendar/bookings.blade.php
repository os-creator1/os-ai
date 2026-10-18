@extends('layouts/contentLayoutMaster')

@section('title', 'Bookings')

@section('page-style')
    @include('customer.business.calendar._styles')
@endsection

@section('content')
    {{--
        The appointments BOOKED (created) in a period, across the Locations this actor may reach: the records behind Home's Bookings card.
        Cancelled bookings are not listed (the card does not count them). Every row opens its own appointment page.
    --}}
    <div class="mb-1">
        <h1 class="h3 mb-25" data-role="bookings-title">Bookings</h1>
        <p class="text-muted mb-0" data-role="bookings-scope">
            @if($created !== null)
                Booked {{ $created->startLocal->format('M j, Y') }} – {{ $created->endLocal->format('M j, Y') }}
                · <a href="{{ route('customer.workspaces.businesses.calendar.bookings', [$workspace->uid, $business->uid]) }}">Show all</a>
            @else
                All bookings
            @endif
        </p>
    </div>

    <x-card :padded="false" data-section="bookings-list">
        @if($appointments->isEmpty())
            <p class="p-2 mb-0 text-muted" data-role="bookings-empty">No bookings in this period.</p>
        @else
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr><th scope="col">Booked</th><th scope="col">Appointment</th><th scope="col">Contact</th><th scope="col">Location</th></tr>
                    </thead>
                    <tbody>
                        @foreach($appointments as $appointment)
                            @php
                                $contact = $contacts[$appointment->contact_id] ?? null;
                                $who = $contact === null ? 'Contact' : ($contact['name'] ?: $contact['phone']);
                            @endphp
                            <tr data-role="booking-row">
                                <td>{{ $appointment->created_at->copy()->setTimezone($timezone)->format('M j, Y g:i A') }}</td>
                                <td>
                                    <a href="{{ route('customer.workspaces.businesses.calendar.appointments.show', [$workspace->uid, $business->uid, $appointment->location->uid, $appointment->uid]) }}" data-role="booking-link">
                                        {{ $appointment->bookingType?->name ?: 'Appointment' }}
                                    </a>
                                    <div class="text-caption text-muted">{{ $appointment->start_at->copy()->setTimezone($timezone)->format('D, M j, Y g:i A') }}</div>
                                </td>
                                <td>{{ $who }}</td>
                                <td>{{ $appointment->location->name ?: 'Location' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    <div class="mt-1">{{ $appointments->links() }}</div>
@endsection
