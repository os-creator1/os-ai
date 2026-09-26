@extends('layouts/contentLayoutMaster')

{{--
    Implementation Contract 08A — a single Client's detail page. Every
    fact here comes from the existing canonical records this Agency
    Workspace already has an ACTIVE Contract 01 relationship for
    (AgencyClientsController::show()) — no analytics dashboard, no
    financial data, no AgencyRebill/SaaS Plan/White Label surface.
--}}

@section('title', $clientWorkspace->name)

@section('content')
    @php
        // Newly-invited-client flow correction — Draft is never View-As-able
        // (ViewAsManager::startAgencyView() requires Active); the button
        // here must not offer an action the server would 404.
        $clientBusinessActive = $business !== null && $business->status === \App\Enums\Business\BusinessStatus::Active;
    @endphp
    <section id="agency-client-detail">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <div>
                <a href="{{ route('customer.workspaces.clients.index', $agencyWorkspace->uid) }}" class="text-caption d-inline-block mb-1">&larr; Back to Clients</a>
                <h2 class="mb-0" data-role="client-workspace-name">{{ $clientWorkspace->name }}</h2>
            </div>
            @if ($clientBusinessActive)
                <form method="POST" action="{{ route('customer.workspaces.clients.view-as', [$agencyWorkspace->uid, $clientWorkspace->uid]) }}" data-role="view-as-form">
                    @csrf
                    <x-button type="submit" variant="primary" data-role="view-as-client">View As this client</x-button>
                </form>
            @else
                <x-badge variant="warning" data-role="view-as-unavailable">Waiting for client setup</x-badge>
            @endif
        </div>

        <div class="row">
            <div class="col-12 col-lg-8">
                <x-card title="Client business">
                    @if ($business !== null && ! $clientBusinessActive)
                        <x-alert variant="warning" class="mb-2" data-role="client-draft-notice">
                            This client has not finished setting up their Business yet. "View As" opens once they
                            review and activate it from their own account.
                        </x-alert>
                    @endif

                    @if ($business !== null)
                        <dl class="row mb-0" data-role="client-business-facts">
                            <dt class="col-sm-4">Business name</dt>
                            <dd class="col-sm-8" data-role="business-name">{{ $business->name }}</dd>

                            <dt class="col-sm-4">Locations</dt>
                            <dd class="col-sm-8" data-role="location-count">{{ $locationCount }}</dd>

                            <dt class="col-sm-4">Primary location</dt>
                            <dd class="col-sm-8" data-role="primary-location">
                                {{ $primaryLocation?->name ?? $primaryLocation?->address_line_1 ?? 'Not set' }}
                            </dd>
                        </dl>
                    @else
                        <x-alert variant="warning" data-role="business-data-integrity-warning">
                            @if ($businessCount === 0)
                                This client has no Business on file. This is a data-integrity
                                issue outside this screen's scope to repair.
                            @else
                                This client has {{ $businessCount }} Businesses on file, more
                                than the one this screen expects. This is a data-integrity
                                issue outside this screen's scope to repair.
                            @endif
                        </x-alert>
                    @endif
                </x-card>
            </div>

            <div class="col-12 col-lg-4">
                <x-card title="Relationship">
                    <dl class="row mb-0" data-role="relationship-facts">
                        <dt class="col-sm-5">Status</dt>
                        <dd class="col-sm-7">
                            <x-badge variant="success" data-role="relationship-status">{{ ucfirst($relationship->status->value) }}</x-badge>
                        </dd>

                        <dt class="col-sm-5">Managed since</dt>
                        <dd class="col-sm-7" data-role="established-at">{{ $relationship->established_at?->format('M j, Y') }}</dd>
                    </dl>
                </x-card>

                <x-card title="Account access">
                    <p class="mb-0" data-role="account-access-state">
                        @if ($accessDecision->isLocked())
                            <x-badge variant="danger">{{ $accessDecision->heading ?? 'Locked' }}</x-badge>
                        @else
                            <x-badge variant="success">Usable</x-badge>
                        @endif
                    </p>
                </x-card>

                {{--
                    Lane C §C6/§C8 — this client's SaaS subscription with THIS
                    agency.

                    Offering a plan proposes a charge; it never makes one. The
                    client reviews the terms on their own billing page and
                    authorises the payment themselves, because financial consent
                    belongs to whoever is being charged.
                --}}
                <x-card title="SaaS subscription">
                    @if ($agencySubscription === null)
                        <p class="mb-0" data-role="agency-saas-client-state">No plan offered yet.</p>
                    @else
                        <dl class="row mb-0" data-role="agency-saas-client-facts">
                            <dt class="col-sm-5">Status</dt>
                            <dd class="col-sm-7" data-role="agency-saas-client-state">{{ $agencySubscription->status->value }}</dd>

                            @if ($agencySubscription->price_snapshot !== null)
                                <dt class="col-sm-5">They pay</dt>
                                <dd class="col-sm-7" data-role="agency-saas-client-price">
                                    {{ $agencySubscription->price_snapshot }} {{ $agencySubscription->currency_code }}
                                    / {{ $agencySubscription->billing_cycle_snapshot }}
                                </dd>
                            @endif
                        </dl>
                    @endif

                    @if ($isAgencyOwner && count($sellablePlans) > 0 && ($agencySubscription === null || $agencySubscription->isOffer()))
                        <form method="POST"
                              action="{{ route('customer.workspaces.agency.saas.clients.offer', [$agencyWorkspace->uid, $clientWorkspace->uid]) }}"
                              data-role="agency-saas-offer-form">
                            @csrf
                            <label>Plan
                                <select name="plan_uid" required>
                                    @foreach ($sellablePlans as $plan)
                                        <option value="{{ $plan->uid }}">
                                            {{ $plan->name }} — {{ $plan->price }} {{ $plan->currency_code }} / {{ $plan->billing_cycle }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <input type="checkbox" name="confirm" value="1" required>
                                I understand this offers the plan; my client authorises the payment themselves.
                            </label>
                            <button type="submit">Offer this plan</button>
                        </form>
                    @endif

                    @if ($isAgencyOwner && $agencySubscription !== null && $agencySubscription->isOffer())
                        <form method="POST"
                              action="{{ route('customer.workspaces.agency.saas.clients.offer.withdraw', [$agencyWorkspace->uid, $clientWorkspace->uid]) }}"
                              data-role="agency-saas-withdraw-form">
                            @csrf
                            <button type="submit">Withdraw offer</button>
                        </form>
                    @endif
                </x-card>

                {{--
                    Contract 09 §6.2/§7 — AgencyRebill: the Agency funds this
                    Business's usage wallet, charged on the PLATFORM's Stripe
                    account with the Agency as payer. Deliberately a separate
                    card from "SaaS subscription" above: that is the client
                    paying the Agency for their plan (lane C); this is the
                    Agency paying for the client's usage (lane D). Never the
                    same control, never the same consent.

                    Three distinct states, not two: payer_type stays
                    agency_rebill after a revoke (revokeAgencyRebillConsent()
                    never falls back to another payer), so payer_type alone
                    cannot tell "never configured" apart from "revoked."
                    agency_rebill_consented_at is the tie-breaker.
                --}}
                @if ($isAgencyOwner && $business !== null)
                    @php
                        $isAgencyRebillPayer = $billingResponsibility['payer_type'] === \App\Enums\Usage\PayerType::AgencyRebill->value;
                        $consentedAt = $billingResponsibility['agency_rebill_consented_at'];
                    @endphp
                    <x-card title="Usage funding (AgencyRebill)">
                        <dl class="row mb-0" data-role="agency-rebill-facts">
                            <dt class="col-sm-5">Who pays</dt>
                            <dd class="col-sm-7" data-role="agency-rebill-payer-type">{{ $billingResponsibility['payer_type'] }}</dd>
                        </dl>

                        @if ($isAgencyRebillPayer && $consentedAt !== null)
                            <p class="mb-1" data-role="agency-rebill-active">Your Agency currently funds this Business's usage. Charges are processed on the platform's Stripe account, with your Agency as payer.</p>
                            <form method="POST"
                                  action="{{ route('customer.workspaces.clients.agency-rebill.revoke', [$agencyWorkspace->uid, $clientWorkspace->uid]) }}"
                                  data-role="agency-rebill-revoke-form">
                                @csrf
                                <button type="submit">Stop funding this Business</button>
                            </form>
                        @elseif ($isAgencyRebillPayer && $consentedAt === null)
                            <p class="mb-1" data-role="agency-rebill-revoked">Funding is paused. Your Agency previously consented, then stopped — no new usage is being funded until you re-grant consent.</p>
                            <form method="POST"
                                  action="{{ route('customer.workspaces.clients.agency-rebill.assign', [$agencyWorkspace->uid, $clientWorkspace->uid]) }}"
                                  data-role="agency-rebill-assign-form">
                                @csrf
                                <label>
                                    <input type="checkbox" name="confirm" value="1" required>
                                    I understand my Agency will again fund this Business's usage, charged on the platform's Stripe account with my Agency as payer, until I stop.
                                </label>
                                <button type="submit">Resume funding this Business</button>
                            </form>
                        @else
                            <p class="mb-1" data-role="agency-rebill-not-configured">Not configured. This Business is not funded by your Agency.</p>
                            <form method="POST"
                                  action="{{ route('customer.workspaces.clients.agency-rebill.assign', [$agencyWorkspace->uid, $clientWorkspace->uid]) }}"
                                  data-role="agency-rebill-assign-form">
                                @csrf
                                <label>
                                    <input type="checkbox" name="confirm" value="1" required>
                                    I understand my Agency will fund this Business's usage, charged on the platform's Stripe account with my Agency as payer, until I stop.
                                </label>
                                <button type="submit">Fund this Business's usage</button>
                            </form>
                        @endif

                        {{--
                            RFC-005 Funding Provider-Flow Correction Contract
                            §9/§11 correction — funding setup and the top-up
                            action are shown whenever payer_type is
                            agency_rebill, REGARDLESS of consent state
                            (active or revoked): Contract 09 deliberately
                            allows funding CONFIGURATION without standing
                            consent (PaymentInstrumentManager's own
                            assertAuthorizedFundingPayer() — configuring
                            must never deadlock on the very consent it
                            exists to grant), so an owner whose Agency
                            revoked consent can still set up or replace a
                            payment method ahead of re-granting. Never shown
                            for "not configured" (payer_type isn't even
                            agency_rebill yet — there is nothing here to
                            configure funding FOR).
                        --}}
                        @if ($isAgencyRebillPayer)
                            <div class="mt-2" data-role="agency-funding-setup-section">
                                @if ($agencyPaymentMethod !== null)
                                    <p class="mb-1" data-role="agency-funding-payment-method">
                                        {{ ucfirst((string) $agencyPaymentMethod['brand']) }} &bull;&bull;&bull;&bull; {{ $agencyPaymentMethod['last_four'] }} on file for funding.
                                    </p>
                                @endif

                                {{--
                                    RFC-005 Funding Provider-Flow Correction
                                    Contract §9/§11 — the separate,
                                    contract-named surface for establishing
                                    (or replacing) the Agency's own provider
                                    customer/payment method, mirroring the
                                    client's own
                                    usage-billing/partials/payment-method.blade.php
                                    exactly (same Stripe.js SetupIntent
                                    flow, same two-step
                                    create-then-confirm), scoped to the
                                    Agency instead of a client Business.
                                    Offered whenever no saved method exists
                                    yet — never a saved-card prerequisite
                                    for top-up itself, only an optional,
                                    separate control.
                                --}}
                                @if ($agencyPaymentMethod === null)
                                    <p class="mb-1" data-role="agency-funding-setup-required">Funding setup required before you can fund this Business. Add a payment method for your Agency below.</p>

                                    <div id="agency-funding-payment-method-setup-form" class="mb-2" style="max-width: 360px;">
                                        <div id="agency-funding-card-element" class="form-control mb-1" style="height: 40px; padding: 10px;"></div>
                                        <div id="agency-funding-card-errors" class="text-danger small mb-1" role="alert"></div>
                                        <button type="button"
                                                id="agency-funding-payment-method-submit"
                                                class="btn btn-outline-primary"
                                                data-role="agency-funding-setup-submit"
                                                data-action-url="{{ route('customer.workspaces.clients.funding.payment-method.setup-intent', [$agencyWorkspace->uid, $clientWorkspace->uid]) }}"
                                                data-confirm-url="{{ route('customer.workspaces.clients.funding.payment-method.confirm', [$agencyWorkspace->uid, $clientWorkspace->uid]) }}"
                                                data-publishable-key="{{ config('services.stripe.key') }}">
                                            Set up funding payment method
                                        </button>
                                    </div>
                                @endif

                                {{--
                                    RFC-005 Funding Provider-Flow Correction
                                    Contract §9/§11 — the actual funding
                                    action's ONLY gates are active standing
                                    consent and an existing Agency provider
                                    customer. NEVER a saved payment method:
                                    the locked contract explicitly drops
                                    that requirement for ManualTopUp —
                                    hosted Checkout collects the card.
                                    initiateTopUp() itself still enforces
                                    both gates server-side (and denies
                                    no_provider_customer, unmodified, if
                                    this fact were ever stale); this is
                                    presentation only. Posts to
                                    AgencyClientFundingController, which
                                    reuses
                                    UsageBillingCheckoutManager::initiateTopUp()
                                    unchanged — the redirect that follows is
                                    Stripe's own hosted Checkout page.
                                --}}
                                @if ($consentedAt !== null && $agencyProviderCustomerExists)
                                    <form method="POST"
                                          action="{{ route('customer.workspaces.clients.funding.top-up.initiate', [$agencyWorkspace->uid, $clientWorkspace->uid]) }}"
                                          data-role="agency-rebill-top-up-form"
                                          class="mt-2">
                                        @csrf
                                        <label>
                                            Amount ({{ $walletCurrencyCode }})
                                            <input type="text" name="amount" inputmode="decimal" placeholder="5.00" required data-role="agency-rebill-top-up-amount">
                                        </label>
                                        <button type="submit">Fund this Business's wallet now</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </x-card>
                @endif
            </div>
        </div>
    </section>

    {{--
        RFC-005 Funding Provider-Flow Correction Contract §9/§11 — the
        Agency funding-setup SetupIntent flow, mirrored line-for-line from
        resources/views/customer/business/usage-billing/show.blade.php's
        own payment-method script, scoped to the agency-funding-* element
        ids above instead of usage-billing-*. Stripe.js is loaded only
        when the setup button is actually rendered (i.e. no Agency payment
        method exists yet). The publishable key is the only Stripe-related
        value embedded server-side; the client_secret is fetched
        per-request and never stored.
    --}}
    <script>
        (function () {
            var agencyFundingButton = document.getElementById('agency-funding-payment-method-submit');

            if (agencyFundingButton) {
                var stripeScript = document.createElement('script');
                stripeScript.src = 'https://js.stripe.com/v3/';
                stripeScript.onload = function () {
                    var stripe = Stripe(agencyFundingButton.getAttribute('data-publishable-key'));
                    var elements = stripe.elements();
                    var card = elements.create('card');
                    card.mount('#agency-funding-card-element');

                    agencyFundingButton.addEventListener('click', function () {
                        var errorEl = document.getElementById('agency-funding-card-errors');
                        errorEl.textContent = '';

                        fetch(agencyFundingButton.getAttribute('data-action-url'), {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '',
                                'Accept': 'application/json',
                            },
                        })
                            .then(function (response) { return response.json(); })
                            .then(function (data) {
                                if (data.error) {
                                    errorEl.textContent = data.error;
                                    return;
                                }

                                return stripe.confirmCardSetup(data.client_secret, {
                                    payment_method: { card: card },
                                }).then(function (result) {
                                    if (result.error) {
                                        errorEl.textContent = result.error.message;
                                        return;
                                    }

                                    var confirmForm = document.createElement('form');
                                    confirmForm.method = 'POST';
                                    confirmForm.action = agencyFundingButton.getAttribute('data-confirm-url');

                                    var csrfInput = document.createElement('input');
                                    csrfInput.type = 'hidden';
                                    csrfInput.name = '_token';
                                    csrfInput.value = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';
                                    confirmForm.appendChild(csrfInput);

                                    var setupIntentInput = document.createElement('input');
                                    setupIntentInput.type = 'hidden';
                                    setupIntentInput.name = 'setup_intent';
                                    setupIntentInput.value = result.setupIntent.id;
                                    confirmForm.appendChild(setupIntentInput);

                                    document.body.appendChild(confirmForm);
                                    confirmForm.submit();
                                });
                            });
                    });
                };
                document.head.appendChild(stripeScript);
            }
        })();
    </script>
@endsection
