@extends('layouts/contentLayoutMaster')

@section('title', 'Payment Provider Events')

@section('content')
    <section id="admin-payment-provider-events-index">
        <div class="row">
            <div class="col-12">
                @if (session('flash_success'))
                    <x-alert variant="success" icon="check-circle" class="mb-2">{{ session('flash_success') }}</x-alert>
                @endif

                @if (session('flash_error'))
                    <x-alert variant="danger" icon="alert-circle" class="mb-2">{{ session('flash_error') }}</x-alert>
                @endif
            </div>

            <div class="col-12">
                <x-card title="Exhausted Payment Provider Events">
                    @if ($events->isEmpty())
                        <x-empty-state icon="inbox" title="No exhausted events awaiting review." />
                    @else
                        <x-table :headers="['ID', 'Provider', 'Event type', 'State', 'Attempts', 'Last attempt', 'Received', 'Disposition']">
                            @foreach ($events as $event)
                                <tr>
                                    <td class="text-numeric">{{ $event->id }}</td>
                                    <td>{{ $event->provider->value }}</td>
                                    <td>{{ $event->event_type }}</td>
                                    <td><x-badge variant="warning">{{ $event->state->value }}</x-badge></td>
                                    <td class="text-numeric">{{ $event->attempts }}</td>
                                    <td class="text-caption">{{ $event->last_attempt_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td class="text-caption">{{ $event->received_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.provider-events.dispose', $event->id) }}">
                                            @csrf
                                            <div class="input-group input-group-sm">
                                                <input type="text" name="disposition_note" class="form-control transition-fast" placeholder="Resolution note" required maxlength="5000">
                                                <x-button type="submit" variant="danger" size="sm">Dispose</x-button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Recent Normalized Provider Outcomes">
                    @if ($recentOutcomes->isEmpty())
                        <x-empty-state icon="inbox" title="No normalized outcomes recorded yet." />
                    @else
                        <x-table :headers="['ID', 'Business', 'Funding attempt', 'Outcome', 'Status', 'Currency', 'Reported', 'Outcome delta', 'Wallet delta', 'Policy excess', 'Reason', 'Recorded']">
                            @foreach ($recentOutcomes as $outcome)
                                <tr>
                                    <td class="text-numeric">{{ $outcome->id }}</td>
                                    <td class="text-numeric">{{ $outcome->business_id ?? '—' }}</td>
                                    <td class="text-numeric">{{ $outcome->funding_attempt_id ?? '—' }}</td>
                                    <td><x-badge variant="info">{{ $outcome->normalized_outcome }}</x-badge></td>
                                    <td>{{ $outcome->normalized_status ?? '—' }}</td>
                                    <td>{{ $outcome->normalized_currency_code ?? '—' }}</td>
                                    <td class="text-numeric">{{ $outcome->normalized_reported_amount_micro ?? '—' }}</td>
                                    <td class="text-numeric">{{ $outcome->normalized_outcome_delta_micro ?? '—' }}</td>
                                    <td class="text-numeric">{{ $outcome->normalized_wallet_delta_micro ?? '—' }}</td>
                                    <td class="text-numeric">{{ $outcome->normalized_policy_excess_micro ?? '—' }}</td>
                                    <td class="text-caption">{{ $outcome->normalized_reason ?? '—' }}</td>
                                    <td class="text-caption">{{ $outcome->normalized_recorded_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
