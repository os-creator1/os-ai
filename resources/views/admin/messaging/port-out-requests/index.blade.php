@extends('layouts/contentLayoutMaster')

@section('title', 'Messaging Port-Out Requests')

@section('content')
    <section id="admin-messaging-port-out-requests-index">
        <div class="row">
            <div class="col-12">
                <x-card title="Number Port-Out Requests">
                    @if ($requests->isEmpty())
                        <x-empty-state icon="inbox" title="No port-out requests yet." />
                    @else
                        <x-table :headers="['ID', 'Business', 'Phone number', 'Status', 'Requested by', 'Requested', 'Cancelled']">
                            @foreach ($requests as $request)
                                <tr>
                                    <td class="text-numeric">{{ $request->id }}</td>
                                    <td>{{ $request->business?->name ?? ('#' . $request->business_id) }}</td>
                                    <td class="text-caption">{{ $request->phone_number }}</td>
                                    <td>
                                        <x-badge variant="{{ $request->isActive() ? 'warning' : 'secondary' }}">
                                            {{ $request->status->value }}
                                        </x-badge>
                                    </td>
                                    <td class="text-caption">{{ $request->requestedByUser?->displayName() ?? ('#' . $request->requested_by_user_id) }}</td>
                                    <td class="text-caption">{{ $request->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td class="text-caption">
                                        @if ($request->cancelled_at !== null)
                                            {{ $request->cancelled_at->format('Y-m-d H:i') }}
                                            by {{ $request->cancelledByUser?->displayName() ?? ('#' . $request->cancelled_by_user_id) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-table>
                        {{ $requests->links() }}
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
