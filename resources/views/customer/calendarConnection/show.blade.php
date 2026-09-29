@extends('layouts/contentLayoutMaster')

@section('title', 'Calendar connection')

@section('content')
    @php
        // Implementation Contract 15 §5.5/§12.F — presentation only. Every
        // authorization answer (which connection, whose it is) was decided
        // before this view rendered: findForUser() always resolves the
        // CURRENTLY authenticated user's own connection, never one supplied
        // by the request.
        $state = $connection?->state?->value;
    @endphp

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Your calendar connection</h4>
            <p class="card-text text-muted">
                Connect your own Google or Outlook calendar once. Its busy times are then checked before any
                appointment is booked with you, at every location you're scheduled into.
            </p>

            @if ($connection === null || in_array($state, ['disconnected', 'revoked'], true))
                <div class="d-flex gap-2">
                    <form method="POST" action="{{ route('customer.calendar-connection.connect', ['google']) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">Connect Google Calendar</button>
                    </form>
                    <form method="POST" action="{{ route('customer.calendar-connection.connect', ['outlook']) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary">Connect Outlook Calendar</button>
                    </form>
                </div>
            @elseif ($state === 'pending')
                <p class="mb-2">
                    A connection to <strong>{{ ucfirst($connection->provider->value) }}</strong> is in progress.
                    If you did not complete it, try again below.
                </p>
                <form method="POST" action="{{ route('customer.calendar-connection.connect', [$connection->provider->value]) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Try again</button>
                </form>
            @else
                <p class="mb-2">
                    Connected to <strong>{{ ucfirst($connection->provider->value) }}</strong>
                    @if ($connection->external_account_email)
                        as <strong>{{ $connection->external_account_email }}</strong>
                    @endif
                    .
                </p>
                @if ($connection->last_synced_at)
                    <p class="text-muted mb-2">Last synced {{ $connection->last_synced_at->diffForHumans() }}.</p>
                @endif
                <form method="POST" action="{{ route('customer.calendar-connection.disconnect') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger">Disconnect</button>
                </form>
            @endif
        </div>
    </div>
@endsection
