@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@section('page-style')
    @include('customer.settings._phone-messaging-assets')
@endsection

@php
    $state = $pending['state'];
    $inProgress = in_array($state, ['activating', 'confirming'], true);
    $phone = $pending['phone'] !== '' ? $pending['phone'] : 'your number';
@endphp

@section('content')
<div class="sr-page sr-page--wide pm-page" data-role="number-order-pending" data-state="{{ $state }}" data-status-url="{{ $statusUrl }}">
    @include('customer.settings._sr-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]),
        'title' => $inProgress ? 'Activating your number…' : ($state === 'escalated' ? 'Your number is taking longer than usual' : 'We could not complete your number order'),
        'action' => '',
        'chip' => $inProgress ? 'Activating' : 'Being handled',
        'chipVariant' => $inProgress ? '' : 'warn',
        'description' => $inProgress ? 'Your carrier is finishing setting up your number.' : 'Our team has been alerted. You do not need to contact support.',
    ])

    @if ($inProgress)
        <x-alert variant="info" icon="clock" class="mb-2" data-role="number-confirming">
            <strong class="d-block">
                @if ($state === 'activating') Placing your order with our carrier @else Confirming {{ $phone }} with our carrier @endif
            </strong>
            This usually takes a few minutes. You can leave this page: your number is set up automatically, you will not be charged again,
            and it will appear here (and in Phone &amp; messaging) as soon as it is ready.
            @if ($pending['since'])<span class="d-block text-muted mt-50">Started {{ $pending['since'] }}.</span>@endif
        </x-alert>
        <ol class="pm-hint ps-3" data-role="activation-steps">
            <li>Order placed with the carrier</li>
            <li>Waiting for the carrier to confirm {{ $phone }}</li>
            <li>We attach it to your Business, link your approved registration and turn on calling</li>
        </ol>
    @elseif ($state === 'escalated')
        <x-alert variant="warning" icon="alert-triangle" class="mb-2" data-role="number-escalated">
            <strong class="d-block">{{ $phone }} is taking longer than usual to confirm</strong>
            Our team was alerted automatically and will finish your setup. Your verification is safe, you will not be
            charged twice, and there is nothing you need to do. Please do not try to buy another number.
        </x-alert>
    @elseif ($state === 'failed')
        <x-alert variant="warning" icon="alert-triangle" class="mb-2" data-role="number-order-failed">
            <strong class="d-block">The carrier could not complete the order for {{ $phone }}</strong>
            No number was bought. The payment for this order is being returned to your balance automatically; this page
            will update when that is done, and you can then choose another number.
        </x-alert>
    @else
        <x-alert variant="warning" icon="alert-triangle" class="mb-2" data-role="number-order-failed-review">
            <strong class="d-block">The carrier could not complete the order for {{ $phone }}</strong>
            No number was bought. Our team was alerted automatically and is returning the payment for this order; you do
            not need to contact support. Please do not try to buy another number until this page says you can.
        </x-alert>
    @endif
</div>

<script>
    // Server-side reconciliation does the work whether or not this page is open. This only notices when the state
    // changes (number ready, payment returned, escalated) and then shows the new page. No fake progress, no blocking.
    (function () {
        var root = document.querySelector('[data-role="number-order-pending"]');
        if (!root || !window.fetch) { return; }
        var url = root.getAttribute('data-status-url');
        var current = root.getAttribute('data-state');
        var delay = 6000;

        function poll() {
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (j && j.state && j.state !== current) { window.location.reload(); return; }
                    delay = Math.min(delay * 1.4, 30000);
                    setTimeout(poll, delay);
                })
                .catch(function () { setTimeout(poll, 30000); });
        }

        if (current === 'activating' || current === 'confirming' || current === 'failed') { setTimeout(poll, delay); }
    })();
</script>
@endsection
