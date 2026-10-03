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