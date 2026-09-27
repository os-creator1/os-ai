@extends('layouts/contentLayoutMaster')

@section('title', 'Messaging Provisioning Incidents')

@section('content')
    <section id="admin-messaging-provisioning-incidents-index">
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
                <x-card title="Unresolved Provisioning Incidents">
                    @if ($incidents->isEmpty())
                        <x-empty-state icon="inbox" title="No unresolved provisioning incidents." />
                    @else
                        <x-table :headers="['ID', 'Business', 'Stage', 'Messaging profile', 'Provider number', 'Phone number', 'Number type', 'Error', 'Recorded', 'Resolution']">
                            @foreach ($incidents as $incident)
                                <tr>
                                    <td class="text-numeric">{{ $incident->id }}</td>
                                    <td>{{ $incident->business?->name ?? ('#' . $incident->business_id) }}</td>
                                    <td><x-badge variant="warning">{{ $incident->stage }}</x-badge></td>
                                    <td class="text-caption">{{ $incident->messaging_profile_id ?? '—' }}</td>
                                    <td class="text-caption">{{ $incident->provider_phone_number_id ?? '—' }}</td>
                                    <td class="text-caption">{{ $incident->phone_number ?? '—' }}</td>
                                    <td>{{ $incident->number_type ?? '—' }}</td>
                                    <td class="text-caption">{{ $incident->error_message ?? '—' }}</td>
                                    <td class="text-caption">{{ $incident->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td style="min-width: 260px;">
                                        <form method="POST" action="{{ route('admin.messaging-provisioning-incidents.resolve', $incident->id) }}">
                                            @csrf
                                            <input type="text" name="resolution_note" class="form-control form-control-sm transition-fast mb-1" placeholder="What was reconciled, and how" required maxlength="5000">
                                            <div class="form-check mb-1">
                                                <input type="checkbox" class="form-check-input" id="reconciliation-confirmed-{{ $incident->id }}" name="reconciliation_confirmed" value="1" required>
                                                <label class="form-check-label text-caption" for="reconciliation-confirmed-{{ $incident->id }}">
                                                    I personally checked this number/profile in Telnyx and this platform's own records, and confirm they are reconciled.
                                                </label>
                                            </div>
                                            <p class="text-caption text-muted mb-1">
                                                This is your own attestation — the app has not verified Telnyx on your behalf.
                                            </p>
                                            <x-button type="submit" variant="danger" size="sm">Mark resolved</x-button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </x-table>
                        {{ $incidents->links() }}
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
