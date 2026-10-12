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
        html{color-scheme:light}
        *{box-sizing:border-box}
        body{margin:0;background:#f6f5f2;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#17212b;line-height:1.5}
        .bar{background:{{ $brand['color'] }};color:{{ $brand['ink'] }}}
        .bar-in{max-width:42rem;margin:0 auto;padding:.9rem 1rem;display:flex;align-items:center;gap:.75rem}
        .logo{width:2.25rem;height:2.25rem;border-radius:.5rem;background:rgba(255,255,255,.92);object-fit:contain;flex:none;display:flex;align-items:center;justify-content:center;font-weight:700;color:{{ $brand['color'] }}}
        .bar-name{font-weight:600;font-size:1rem;overflow-wrap:anywhere}
        main{max-width:42rem;margin:0 auto;padding:1.5rem 1rem 3rem}
        .card{background:#fff;border:1px solid #e7e4de;border-radius:1rem;padding:1.5rem;margin-bottom:1rem}
        h1{font-size:1.5rem;line-height:1.25;margin:0 0 .5rem}
        .ok{display:inline-flex;align-items:center;justify-content:center;width:2.5rem;height:2.5rem;border-radius:50%;background:#e6f4ec;color:#1e7b4a;font-size:1.25rem;margin-bottom:.75rem}
        .cta{display:inline-block;margin-top:1rem;padding:.75rem 1.5rem;background:{{ $brand['color'] }};color:{{ $brand['ink'] }};border-radius:.5rem;text-decoration:none;font-weight:600}
        .muted{color:#5b6672;font-size:.9rem}
        @media (max-width:480px){.card{padding:1.15rem}.cta{display:block;text-align:center}}
    </style>
</head>
<body>
<header class="bar" data-role="brand-header">
    <div class="bar-in">
        @if($brand['logo_url'])
            <img class="logo" data-role="brand-logo" src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }} logo">
        @else
            <span class="logo" data-role="brand-initial" aria-hidden="true">{{ $brand['initials'] }}</span>
        @endif
        <span class="bar-name" data-role="brand-name">{{ $brand['name'] }}</span>
    </div>
</header>
<main>
  <section class="card">
    <div class="ok" aria-hidden="true">&#10003;</div>
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
  </section>
</main>
</body>
</html>
