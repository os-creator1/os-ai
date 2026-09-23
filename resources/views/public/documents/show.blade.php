{{--
    Implementation Contract 17 §6.3 — the end customer's view of the FROZEN
    ISSUED version.

    It renders the version the guard resolved, never the open draft: a
    customer must never be shown an in-progress revision of the thing they
    are being asked to sign.

    Every value is escaped Blade output. Raw output is forbidden here: this
    page renders customer-authored document content on an unauthenticated
    surface.

    NO LEGAL CLAIM (§6.5). The signing copy below describes a typed signature
    and nothing more — never "qualified", "advanced", "identity-verified" or
    "legally binding".

    NO PAYMENT SURFACE. §6.3.2's Pay action is Sub-slice E; this page makes no
    provider call and collects no card data.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $document->title }}</title>
    <style>
        body{font-family:system-ui,sans-serif;max-width:46rem;margin:3rem auto;padding:0 1rem;color:#17212b;line-height:1.5}
        table{width:100%;border-collapse:collapse;margin:1.5rem 0}
        th,td{text-align:left;padding:.55rem .4rem;border-bottom:1px solid #d8dee5}
        td.num,th.num{text-align:right}
        .total{font-weight:600}
        fieldset{border:1px solid #d8dee5;border-radius:.4rem;padding:1rem 1.2rem;margin-top:2rem}
        label{display:block;margin:.9rem 0 .25rem}
        input{font:inherit;padding:.6rem;border:1px solid #9ba7b4;border-radius:.4rem;width:100%;max-width:26rem}
        button{font:inherit;background:#125a9c;color:#fff;border:0;border-radius:.4rem;padding:.7rem 1.1rem;cursor:pointer;margin-top:1.2rem}
        .consent{background:#f4f6f8;border-radius:.4rem;padding:.8rem 1rem;margin-top:1rem}
        .error{color:#a51d24}
        .muted{color:#5a6673;font-size:.92rem}
    </style>
</head>
<body>
<main>
    <p class="muted">{{ $business->name }}</p>
    <h1>{{ $document->title }}</h1>

    @if($document->status === \App\Enums\Documents\DocumentStatus::Signed)
        <p data-role="already-signed">This document was signed on {{ $document->signed_at?->format('j F Y') }}.</p>
    @endif

    <table data-role="lines">
        <thead>
        <tr><th>Item</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Total</th></tr>
        </thead>
        <tbody>
        @foreach($lines as $line)
            <tr data-role="line">
                <td>
                    {{ $line->name }}
                    @if($line->description)<span class="muted d-block">{{ $line->description }}</span>@endif
                </td>
                <td class="num">{{ $line->quantity }}</td>
                <td class="num">{{ number_format($line->unit_price_minor / 100, 2) }}</td>
                <td class="num">{{ number_format($line->line_total_minor / 100, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr class="total"><td colspan="3">Total</td><td class="num" data-role="total">{{ number_format($version->total_minor / 100, 2) }} {{ $version->currency_code }}</td></tr>
        </tfoot>
    </table>

    @if($schedule->isNotEmpty())
        <h2>Payment schedule</h2>
        <table data-role="schedule">
            <tbody>
            @foreach($schedule as $item)
                <tr data-role="schedule-item" data-kind="{{ $item->kind instanceof \BackedEnum ? $item->kind->value : $item->kind }}">
                    <td>{{ ucfirst($item->kind instanceof \BackedEnum ? $item->kind->value : $item->kind) }}</td>
                    <td>@if($item->due_at)Due {{ $item->due_at->format('j F Y') }}@endif</td>
                    <td class="num">{{ number_format($item->amount_minor / 100, 2) }} {{ $item->currency_code }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        {{-- §6.3.2 — payment STATE, rendered from persisted data only. This
             block creates no payment row, no PaymentIntent and no provider
             call; the Pay action below is a separate POST. --}}
        @if($payment['payable_item'] !== null && $payment['can_pay'])
            <p data-role="amount-due">
                Due now: {{ number_format($payment['amount_minor'] / 100, 2) }} {{ $payment['currency_code'] }}
            </p>

            {{-- §11.8 — Stripe.js + the Payment Element, mounted against the
                 BUSINESS's connected account. This application never collects
                 a card number, expiry or CVC: the Element talks to Stripe
                 directly, and Stripe.js performs the confirmation including
                 any SCA step. --}}
            <div id="payment-element" data-role="payment-element"></div>
            <p id="payment-error" class="muted" data-role="payment-error"></p>
            <button id="pay-button" type="button" data-role="pay-button">Pay now</button>

            <script src="https://js.stripe.com/v3/"></script>
            <script>
                (function () {
                    var payButton = document.getElementById('pay-button');
                    var errorBox = document.getElementById('payment-error');
                    var started = false;

                    payButton.addEventListener('click', async function () {
                        // Re-driving is safe and idempotent server-side, but
                        // one in-flight start at a time keeps the UI honest.
                        if (started) { return; }
                        started = true;
                        payButton.disabled = true;

                        try {
                            // The request body is EMPTY: no card data, and no
                            // account — the server derives the connected
                            // account from the payment row (SS7.2.2).
                            var response = await fetch(@json(route('public.documents.pay', ['uid' => $document->uid, 'token' => $paymentToken])), {
                                method: 'POST',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                            });

                            if (!response.ok) { throw new Error('unavailable'); }

                            var data = await response.json();

                            // Initialized with the SAME connected account the
                            // PaymentIntent was created on.
                            var stripe = Stripe(data.publishable_key, { stripeAccount: data.stripe_account });
                            var elements = stripe.elements({ clientSecret: data.client_secret });
                            elements.create('payment').mount('#payment-element');
                            payButton.textContent = 'Confirm payment';
                            payButton.disabled = false;

                            payButton.onclick = async function () {
                                payButton.disabled = true;
                                var result = await stripe.confirmPayment({
                                    elements: elements,
                                    confirmParams: { return_url: window.location.href },
                                });

                                // Reaching here at all means an immediate
                                // client-side error. A successful confirmation
                                // redirects — and that redirect carries NO
                                // authority: only the verified webhook marks
                                // this payment succeeded (SS8.5).
                                if (result.error) {
                                    errorBox.textContent = result.error.message;
                                    payButton.disabled = false;
                                }
                            };
                        } catch (e) {
                            errorBox.textContent = 'Payment is not available right now.';
                            started = false;
                            payButton.disabled = false;
                        }
                    });
                })();
            </script>
        @else
            <p class="muted" data-role="payment-note">
                @if($payment['reason'] === \App\Exceptions\Payments\PaymentStartException::NOT_SIGNED)
                    This document can be paid once it has been signed.
                @elseif($payment['reason'] === \App\Exceptions\Payments\PaymentStartException::DEPOSIT_OUTSTANDING)
                    The deposit must be paid before the balance.
                @elseif($payment['reason'] === \App\Exceptions\Payments\PaymentStartException::NOTHING_PAYABLE)
                    There is nothing left to pay.
                @else
                    Online payment is not available for this document.
                @endif
            </p>
        @endif
    @endif

    @if($document->requires_signature && $signature === null && $document->status === \App\Enums\Documents\DocumentStatus::Sent)
        <fieldset data-role="sign-form">
            <legend>Sign this document</legend>

            @foreach($formErrors as $messages)
                @foreach($messages as $message)
                    <p class="error" data-role="sign-error">{{ $message }}</p>
                @endforeach
            @endforeach

            <form method="POST" action="{{ route('public.documents.sign', ['uid' => $document->uid, 'token' => request()->route('token')]) }}">
                @csrf
                <label for="signer_name">Your full name</label>
                <input id="signer_name" type="text" name="signer_name" maxlength="160" value="{{ old('signer_name') }}" required>

                <label for="signer_email">Your email address</label>
                <input id="signer_email" type="email" name="signer_email" maxlength="255" value="{{ old('signer_email') }}" required>

                <label for="typed_name">Type your name to sign</label>
                <input id="typed_name" type="text" name="typed_name" maxlength="160" value="{{ old('typed_name') }}" required>

                <p class="consent" data-role="consent-statement">{{ $consentStatement }}</p>

                <button type="submit">Sign document</button>
            </form>
        </fieldset>
    @endif
</main>
</body>
</html>
