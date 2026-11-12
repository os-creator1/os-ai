@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@section('page-style')
    @include('customer.settings._phone-messaging-assets')
@endsection

@section('content')
<div class="sr-page sr-page--wide pm-page" data-role="number-order-pending" data-state="{{ $pending['state'] }}">
    @include('customer.settings._sr-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]),
        'title' => $pending['state'] === 'failed' ? 'We need to look at your number order' : 'Confirming your number',
        'action' => '',
        'chip' => $pending['state'] === 'confirming' ? 'Confirming' : 'Under review',
        'chipVariant' => $pending['state'] === 'confirming' ? '' : 'warn',
        'description' => $pending['state'] === 'confirming'
            ? 'Your carrier is finishing setting up your number.'
            : 'Our team is handling this for you.',
    ])

    @if ($pending['state'] === 'confirming')
        <x-alert variant="info" icon="clock" class="mb-2" data-role="number-confirming">
            <strong class="d-block">Confirming {{ $pending['phone'] }} with our carrier</strong>
            This usually takes a few minutes. You do not need to do anything and you will not be charged again. This
            page updates on its own, and your number will appear here as soon as it is confirmed.
        </x-alert>
    @elseif ($pending['state'] === 'escalated')
        <x-alert variant="warning" icon="alert-triangle" class="mb-2" data-role="number-escalated">
            <strong class="d-block">{{ $pending['phone'] }} is taking longer than usual to confirm</strong>
            Our team has been alerted and will finish your setup. Your verification is safe, you will not be charged
            twice, and there is nothing you need to do. Please do not try to buy another number.
        </x-alert>
    @else
        <x-alert variant="warning" icon="alert-triangle" class="mb-2" data-role="number-order-failed">
            <strong class="d-block">The carrier could not complete the order for {{ $pending['phone'] }}</strong>
            Our team is reviewing it and will follow up about the charge for this order. Please do not try to buy
            another number until we have contacted you.
        </x-alert>
    @endif
</div>

@if ($pending['state'] === 'confirming')
    <script>
        // Check again shortly: once the number is confirmed the page shows it instead of this message.
        setTimeout(function () { window.location.reload(); }, 20000);
    </script>
@endif
@endsection
