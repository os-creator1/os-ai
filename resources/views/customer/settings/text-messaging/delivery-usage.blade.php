@extends('layouts/contentLayoutMaster')

@section('title', 'Delivery & usage')

@php
    $n = static fn (int $value): string => number_format($value);
@endphp

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <a href="{{ route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid]) }}" class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
                <x-ds-icon name="arrow-left" size="16" />
                Back to Text messaging
            </a>
            <h4 class="mb-0">Delivery & usage</h4>
            <p class="text-caption mb-0">Operational detail for this Business's text messages — not a Business outcome, so it lives here rather than on Results.</p>
        </div>
    </div>

    <x-card :padded="true" class="mb-2">
        <p class="text-label mb-2">Outgoing messages, last 30 days</p>
        <dl class="row mb-0">
            <dt class="col-7 fw-normal">
                Sent
                <x-tooltip text="Accepted by the messaging provider. It doesn't confirm the message reached the phone." tabindex="0" data-role="sent-note">
                    <x-ds-icon name="info" size="14" class="text-muted align-text-bottom" aria-hidden="true" />
                </x-tooltip>
            </dt>
            <dd class="col-5 text-end text-numeric mb-1" data-role="delivery-sent">{{ $n($messages->accepted) }}</dd>

            <dt class="col-7 fw-normal">Failed</dt>
            <dd class="col-5 text-end text-numeric mb-1" data-role="delivery-failed">{{ $n($messages->confirmedFailed) }}</dd>

            <dt class="col-7 fw-normal">Processing</dt>
            <dd class="col-5 text-end text-numeric mb-1" data-role="delivery-processing">{{ $n($messages->unresolved()) }}</dd>
        </dl>
        <p class="text-caption text-muted mt-1 mb-0">Out of {{ $n($messages->outbound) }} outgoing message{{ $messages->outbound === 1 ? '' : 's' }}.</p>
        <p class="text-caption text-muted mb-0">Received: {{ $n($messages->inbound) }}.</p>
    </x-card>

    <x-card title="Daily volume" :padded="true">
        <div class="table-responsive">
            <table class="table table-sm mb-0" data-role="delivery-volume-table">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col" class="text-end">Sent</th>
                        <th scope="col" class="text-end">Received</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($messageVolume->dates as $index => $date)
                        <tr>
                            <td>{{ $date }}</td>
                            <td class="text-end">{{ $n($messageVolume->series['accepted'][$index] ?? 0) }}</td>
                            <td class="text-end">{{ $n($messageVolume->series['incoming'][$index] ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-caption text-muted">No activity in this period yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
@endsection
