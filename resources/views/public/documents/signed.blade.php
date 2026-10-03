{{--
    Implementation Contract 17 §6.5 — the post-signature confirmation.
    Contract 17B §7 — and the step after it: a signer is never left on a dead
    page. When a payment is payable now the primary action leads to the secure
    document page's payment section; otherwise the page says what is due next.

    Makes NO legal claim: it does not describe the record as a qualified or
    advanced signature, as identity-verified, or as legally sufficient
    anywhere. It states only what actually happened.
--}}
@php
    $payableNow = $payment['payable_item'] !== null && $payment['can_pay'];
    $due = $nextItem?->due_at?->copy()->setTimezone($timezone)->format('j F Y');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @if($payableNow)
        {{-- A convenience only: the button below is the guaranteed path. --}}
        <meta http-equiv="refresh" content="4;url={{ $payUrl }}">
    @endif
    <title>Signature recorded</title>
    <style>
        body{font-family:system-ui,sans-serif;max-width:42rem;margin:3rem auto;padding:0 1rem;color:#17212b}
        .cta{display:inline-block;margin-top:1rem;padding:.75rem 1.5rem;background:#1d4ed8;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:600}
        .muted{color:#5b6672}
    </style>
</head>
<body>
<main>
    <h1>Signature recorded</h1>
    <p data-role="signed-ok"><strong>Signed successfully.</strong></p>
    <p>Thank you. Your typed signature for &ldquo;{{ $document->title }}&rdquo; was recorded on {{ $document->signed_at?->format('j F Y') }}.</p>
    <p>{{ $parties['business_name'] }} has been notified.</p>

    @if($payableNow)
        <p data-role="amount-due">
            Next step: {{ number_format($payment['amount_minor'] / 100, 2) }} {{ $payment['currency_code'] }} is due now.
        </p>
        <a class="cta" data-role="continue-to-payment" href="{{ $payUrl }}">Continue to payment</a>
        <p class="muted">You will be taken to the payment page automatically in a few seconds.</p>
    @elseif($nextItem !== null)
        <p data-role="next-payment-due">
            @if($payment['reason'] === \App\Exceptions\Payments\PaymentStartException::DEPOSIT_OUTSTANDING)
                Your next payment of {{ number_format($nextItem->amount_minor / 100, 2) }} {{ $nextItem->currency_code }} is due after the deposit.
            @elseif($due !== null)
                Your next payment of {{ number_format($nextItem->amount_minor / 100, 2) }} {{ $nextItem->currency_code }} is due on {{ $due }}.
            @elseif($nextItem->kind === \App\Enums\Documents\PaymentScheduleItemKind::Balance)
                Your next payment of {{ number_format($nextItem->amount_minor / 100, 2) }} {{ $nextItem->currency_code }} is due after the deposit.
            @else
                Your payment of {{ number_format($nextItem->amount_minor / 100, 2) }} {{ $nextItem->currency_code }} is due once online payment is available.
            @endif
            {{ $parties['business_name'] }} will be in touch about how to pay.
        </p>
        <p class="muted">You can close this page.</p>
    @else
        <p class="muted">You can close this page.</p>
    @endif
</main>
</body>
</html>
