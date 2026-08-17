@extends('layouts/contentLayoutMaster')

@section('title', 'Payment Provider Events')

@section('content')
    <section id="admin-payment-provider-events-index">
        <div class="row">
            <div class="col-12">
                @if (session('flash_success'))
                    <div class="alert alert-success">{{ session('flash_success') }}</div>
                @endif

                @if (session('flash_error'))
                    <div class="alert alert-danger">{{ session('flash_error') }}</div>
                @endif
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Exhausted Payment Provider Events</h4>
                    </div>
                    <div class="card-body">
                        @if ($events->isEmpty())
                            <p class="mb-0">No exhausted events awaiting review.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>ID</th>
                                            <th>Provider</th>
                                            <th>Event type</th>
                                            <th>State</th>
                                            <th>Attempts</th>
                                            <th>Last attempt</th>
                                            <th>Received</th>
                                            <th>Disposition</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($events as $event)
                                            <tr>
                                                <td>{{ $event->id }}</td>
                                                <td>{{ $event->provider->value }}</td>
                                                <td>{{ $event->event_type }}</td>
                                                <td>{{ $event->state->value }}</td>
                                                <td>{{ $event->attempts }}</td>
                                                <td>{{ $event->last_attempt_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                                <td>{{ $event->received_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                                <td>
                                                    <form method="POST" action="{{ route('admin.provider-events.dispose', $event->id) }}">
                                                        @csrf
                                                        <div class="input-group input-group-sm">
                                                            <input type="text" name="disposition_note" class="form-control" placeholder="Resolution note" required maxlength="5000">
                                                            <button type="submit" class="btn btn-outline-danger">Dispose</button>
                                                        </div>
                                                    </form>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
