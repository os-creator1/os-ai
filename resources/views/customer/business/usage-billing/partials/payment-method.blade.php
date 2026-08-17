@php
    // RFC-005 M3 contract §18.1/§18.2 — presentation-only, every value
    // already formatted by the presenter; never a raw PaymentMethod ID,
    // never a client secret rendered as literal text.
@endphp

<div id="usage-billing-payment-method-status" class="mb-2">
    @if ($dashboard->paymentMethod === null)
        <p class="mb-1">No payment method on file yet.</p>
    @else
        <p class="mb-1">
            {{ ucfirst((string) $dashboard->paymentMethod['brand']) }} &bull;&bull;&bull;&bull; {{ $dashboard->paymentMethod['last_four'] }},
            exp {{ str_pad((string) $dashboard->paymentMethod['expiry_month'], 2, '0', STR_PAD_LEFT) }}/{{ $dashboard->paymentMethod['expiry_year'] }}
        </p>
    @endif
</div>

<div id="usage-billing-payment-method-setup-form" class="mb-2" style="max-width: 360px;">
    <div id="usage-billing-card-element" class="form-control mb-1" style="height: 40px; padding: 10px;"></div>
    <div id="usage-billing-card-errors" class="text-danger small mb-1" role="alert"></div>
    <button type="button" id="usage-billing-payment-method-submit" class="btn btn-outline-primary" data-action-url="{{ route('customer.workspaces.businesses.usage-billing.payment-method.setup-intent', [$workspaceUid, $businessUid]) }}" data-confirm-url="{{ route('customer.workspaces.businesses.usage-billing.payment-method.confirm', [$workspaceUid, $businessUid]) }}" data-publishable-key="{{ config('services.stripe.key') }}">
        {{ $dashboard->paymentMethod === null ? 'Set up payment method' : 'Replace payment method' }}
    </button>
</div>

@if ($dashboard->paymentMethod !== null)
    <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.payment-method.detach', [$workspaceUid, $businessUid, $dashboard->paymentMethod['id'] ?? 0]) }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-outline-danger btn-sm">Remove</button>
    </form>
@endif
