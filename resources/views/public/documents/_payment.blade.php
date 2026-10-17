@php
    // Presentation only, from the FROZEN current schedule already on the page.
    $settledItems = $schedule->filter(fn ($row) => $row->status === \App\Enums\Documents\PaymentScheduleItemStatus::Paid);
    $pendingItems = $schedule->filter(fn ($row) => $row->status === \App\Enums\Documents\PaymentScheduleItemStatus::Pending);
    $receivedMinor = (int) $settledItems->sum('amount_minor');
    $remainingMinor = (int) $pendingItems->sum('amount_minor');
    $scheduleCurrency = (string) ($schedule->first()?->currency_code ?? '');
    $showForm = $payment['payable_item'] !== null && $payment['can_pay'] && ! $paymentProcessing;
@endphp

{{-- The inline result of a payment attempt: "Confirming your payment…", success,
     processing, failure, cancellation or an uncertain provider answer. It is
     filled in by the script below from the server's own payment status — the
     browser coming back from Stripe is never treated as proof of payment. --}}
<div id="payment-status" data-role="payment-status" role="status" aria-live="polite" tabindex="-1" hidden></div>

@if($receivedMinor > 0 && $remainingMinor > 0)
    <p class="muted" data-role="payment-received-summary">
        Received so far: {{ number_format($receivedMinor / 100, 2) }} {{ $scheduleCurrency }}.
        Remaining balance: {{ number_format($remainingMinor / 100, 2) }} {{ $scheduleCurrency }}.
    </p>
@endif

<div id="payment-form" data-role="payment-form">
@if($showForm)
            <p data-role="amount-due">
                Due now: {{ number_format($payment['amount_minor'] / 100, 2) }} {{ $payment['currency_code'] }}
            </p>

            {{-- §11.8 — Stripe.js + the Payment Element, mounted against the
                 BUSINESS's connected account. This application never collects
                 a card number, expiry or CVC: the Element talks to Stripe
                 directly, and Stripe.js performs the confirmation including
                 any SCA step. --}}
            <div id="payment-element" data-role="payment-element"></div>
            <p id="payment-error" class="muted" data-role="payment-error" role="alert"></p>
            <button id="pay-button" type="button" data-role="pay-button">Pay now</button>

            <script src="https://js.stripe.com/v3/"></script>
@elseif($paymentProcessing)
            {{-- The "being confirmed" notice above the document already says so;
                 deliberately no Pay button while an attempt is in flight. --}}
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
</div>

<script>
    (function () {
        var statusUrl = @json(route('public.documents.pay.status', ['uid' => $document->uid, 'token' => $paymentToken]));
        var payUrl = @json(route('public.documents.pay', ['uid' => $document->uid, 'token' => $paymentToken]));
        var csrf = @json(csrf_token());

        var statusBox = document.getElementById('payment-status');
        var formWrap = document.getElementById('payment-form');
        var payButton = document.getElementById('pay-button');
        var errorBox = document.getElementById('payment-error');

        // One deterministic return URL: this document's own path plus a fixed
        // marker. Whatever Stripe appends is removed on return (below), so
        // repeated attempts can never accumulate query parameters, and the
        // PaymentIntent client secret does not linger in the address bar.
        var cleanPath = window.location.pathname;
        var returnUrl = window.location.origin + cleanPath + '?payment_return=1';

        // ---- inline status panel ------------------------------------------
        function money(minor, currency) {
            return (Number(minor) / 100).toFixed(2) + (currency ? ' ' + currency : '');
        }

        function el(tag, text, attrs) {
            var node = document.createElement(tag);
            if (text) { node.textContent = text; }
            Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
            return node;
        }

        function button(label, handler, role) {
            var b = el('button', label, { type: 'button', 'data-role': role });
            b.style.marginTop = '.5rem';
            b.addEventListener('click', handler);
            return b;
        }

        function show(kind, lines, actions, tick) {
            statusBox.hidden = false;
            statusBox.className = 'pd-notice';
            statusBox.setAttribute('data-state', kind);
            while (statusBox.firstChild) { statusBox.removeChild(statusBox.firstChild); }

            var head = el('p', null, { 'data-role': 'payment-status-title' });
            if (tick) {
                var mark = el('span', '✓', { 'aria-hidden': 'true' });
                mark.style.cssText = 'display:inline-block;width:1.4rem;height:1.4rem;line-height:1.4rem;text-align:center;border-radius:50%;background:var(--pd-accent);color:var(--pd-ink);margin-right:.5rem;font-weight:700';
                head.appendChild(mark);
            }
            var strong = el('strong', lines[0]);
            head.appendChild(strong);
            statusBox.appendChild(head);

            lines.slice(1).forEach(function (line) {
                statusBox.appendChild(el('p', line, { 'data-role': 'payment-status-detail' }));
            });
            (actions || []).forEach(function (a) { statusBox.appendChild(a); });
        }

        function hideForm() {
            if (formWrap) { formWrap.hidden = true; }
        }

        function reloadClean() {
            // The page is re-rendered from persisted state, which decides
            // whether a Pay button is offered at all.
            window.location.reload();
        }

        // ---- status check / bounded polling --------------------------------
        var MAX_POLLS = 15;        // ~45 s at the intervals below
        var pollTimer = null;
        var polls = 0;
        var checking = false;

        function confirming() {
            show('confirming', ['Confirming your payment…', 'Please keep this page open. Do not pay again.'], []);
        }

        function fetchStatus() {
            return fetch(statusUrl, { method: 'GET', headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) { throw new Error('status'); }
                    return response.json();
                });
        }

        function check(manual) {
            if (checking) { return; }
            checking = true;
            if (manual) { confirming(); }

            fetchStatus().then(function (data) {
                checking = false;
                render(data, manual);
            }).catch(function () {
                checking = false;
                unreachable(manual);
            });
        }

        function schedulePoll() {
            window.clearTimeout(pollTimer);
            if (polls >= MAX_POLLS) { return stillPending(); }
            polls += 1;
            pollTimer = window.setTimeout(function () { check(false); }, polls <= 5 ? 2000 : 3000);
        }

        function startPolling() {
            polls = 0;
            hideForm();
            confirming();
            check(false);
        }

        function refreshButton() {
            return button('Check status', function () { polls = 0; check(true); }, 'payment-refresh');
        }

        function stillPending() {
            show('pending', [
                'Your payment is still being confirmed.',
                'This can take a little longer than usual. Your payment has not been lost, and you do not need to pay again. This page will show the result once it is confirmed.',
            ], [refreshButton()]);
        }

        function unreachable(manual) {
            // Keep the customer's context; never invite another charge while the
            // earlier outcome is unknown.
            show('unreachable', [
                'We could not check your payment just now.',
                'Your connection or our payment provider may be briefly unavailable. If you have already paid you do not need to pay again — check the status again in a moment.',
            ], [refreshButton()]);
        }

        function render(data, manual) {
            window.clearTimeout(pollTimer);
            var amount = money(data.amount_minor, data.currency_code);

            if (data.state === 'succeeded') {
                hideForm();
                var lines = ['Payment received — ' + amount];
                var actions = [];
                if (data.document_paid) {
                    lines.push('Paid in full. Thank you — a confirmation has been emailed to you.');
                } else {
                    lines.push('Thank you — your payment of ' + amount + ' has been received.');
                    lines.push('Remaining balance: ' + money(data.remaining_minor, data.currency_code) + '.');
                    actions.push(button('Pay the remaining balance', reloadClean, 'payment-continue'));
                }
                show('succeeded', lines, actions, true);
                return;
            }

            if (data.uncertain) {
                // The provider could not be asked, or its answer did not match
                // our records: do not guess in either direction.
                if (polls < MAX_POLLS && !manual) { confirming(); return schedulePoll(); }
                return stillPending();
            }

            if (data.state === 'processing') {
                show('processing', [
                    'Your payment is being confirmed.',
                    'Your bank is still processing it. Please do not pay again — this page updates automatically.',
                ], []);
                return schedulePoll();
            }

            hideForm();

            if (data.state === 'failed') {
                show('failed', [
                    'Your payment did not go through.',
                    'No payment was taken. You can try again with the same or a different payment method.',
                ], data.can_retry ? [button('Try again', reloadClean, 'payment-retry')] : []);
            } else if (data.state === 'canceled') {
                show('canceled', [
                    'This payment was canceled.',
                    'No payment was taken.',
                ], data.can_retry ? [button('Start a new payment', reloadClean, 'payment-retry')] : []);
            } else if (data.state === 'requires_action' || data.state === 'created') {
                show('incomplete', [
                    'Your payment was not completed.',
                    'The extra verification step, if any, was not finished. No payment has been taken yet.',
                ], data.can_retry ? [button('Return to payment', reloadClean, 'payment-retry')] : []);
            } else {
                show('unknown', [
                    'We could not find a payment to confirm.',
                    'No payment has been recorded for this document yet.',
                ], [button('Return to payment', reloadClean, 'payment-retry')]);
            }
        }

        // ---- Stripe redirect return ------------------------------------------
        // Recognised by the marker we put in return_url or by the parameters
        // Stripe appends. NOTHING from the query string is sent anywhere or
        // trusted: the server resolves the attempt from the document link and
        // its own payment rows, and decides the outcome itself.
        var query = new URLSearchParams(window.location.search);
        var isReturn = query.get('payment_return') === '1'
            || query.has('payment_intent_client_secret')
            || query.has('redirect_status');

        if (isReturn) {
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', cleanPath + '#pay');
            }
            startPolling();
        }

        // ---- Payment Element --------------------------------------------------
        if (!payButton) { return; }

        var LABELS = {
            idle: 'Pay now',
            loading: 'Loading secure payment form…',
            ready: 'Confirm payment',
            submitting: 'Processing payment…',
            done: 'Payment confirmed'
        };
        var phase = 'idle';
        var stripe = null;
        var elements = null;

        function setPhase(next) {
            phase = next;
            payButton.textContent = LABELS[next];
            payButton.disabled = (next === 'loading' || next === 'submitting' || next === 'done');
            payButton.setAttribute('aria-busy', next === 'loading' || next === 'submitting' ? 'true' : 'false');
        }

        function fail(message) {
            errorBox.textContent = message;
        }

        async function initialise() {
            setPhase('loading');
            errorBox.textContent = '';

            try {
                if (typeof Stripe === 'undefined') { throw new Error('stripe-js'); }

                // The request body is EMPTY: no card data, and no account — the
                // server derives the connected account from the payment row.
                var response = await fetch(payUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    credentials: 'same-origin'
                });

                if (response.status === 409) {
                    // An earlier attempt already settled (second tab, double
                    // click). Show the real outcome instead of a card form.
                    startPolling();
                    return;
                }

                if (!response.ok) { throw new Error('unavailable'); }

                var data = await response.json();

                // Initialised with the SAME connected account the
                // PaymentIntent was created on. Built once per page.
                if (elements === null) {
                    stripe = Stripe(data.publishable_key, { stripeAccount: data.stripe_account });
                    elements = stripe.elements({ clientSecret: data.client_secret });
                    elements.create('payment').mount('#payment-element');
                }

                setPhase('ready');
            } catch (e) {
                fail('Payment is not available right now. Please check your connection and try again.');
                setPhase('idle');
            }
        }

        async function confirm() {
            setPhase('submitting');
            errorBox.textContent = '';

            var result;
            try {
                result = await stripe.confirmPayment({
                    elements: elements,
                    confirmParams: { return_url: returnUrl },
                    redirect: 'if_required'
                });
            } catch (e) {
                // Outcome unknown: confirm on the server before anything else.
                startPolling();
                return;
            }

            if (result.error) {
                // An immediate, client-side error (declined card, validation).
                // The same intent stays usable for a retry.
                fail(result.error.message || 'The payment could not be completed. Please try again.');
                setPhase('ready');
                return;
            }

            // No redirect was needed. This still proves nothing by itself: only
            // the server's verified status can mark the payment confirmed.
            setPhase('done');
            startPolling();
        }

        setPhase('idle');

        payButton.addEventListener('click', function () {
            if (phase === 'idle') { initialise(); }
            else if (phase === 'ready') { confirm(); }
        });
    })();
</script>
