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
    <section id="agency-client-detail">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <div>
                <a href="{{ route('customer.workspaces.clients.index', $agencyWorkspace->uid) }}" class="text-caption d-inline-block mb-1">&larr; Back to Clients</a>
                <h2 class="mb-0" data-role="client-workspace-name">{{ $clientWorkspace->name }}</h2>
            </div>
            <form method="POST" action="{{ route('customer.workspaces.clients.view-as', [$agencyWorkspace->uid, $clientWorkspace->uid]) }}" data-role="view-as-form">
                @csrf
                <x-button type="submit" variant="primary" data-role="view-as-client">View As this client</x-button>
            </form>
        </div>

        <div class="row">
            <div class="col-12 col-lg-8">
                <x-card title="Client business">
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

                            {{--
                                Contract 09 §12 — the actual funding action.
                                Only shown once consent is active: a
                                not-configured or revoked Business must grant
                                (or re-grant) first, above. Posts to
                                AgencyClientFundingController, which reuses
                                UsageBillingCheckoutManager::initiateTopUp()
                                unchanged — the redirect that follows is
                                Stripe's own hosted Checkout page.
                            --}}
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
                    </x-card>
                @endif
            </div>
        </div>
    </section>
@endsection
