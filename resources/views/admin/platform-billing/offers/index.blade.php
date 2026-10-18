{{--
    Platform Owner → Billing & Revenue → Private Offers.

    Create Offer → Copy Link. The owner never opens Stripe: the recurring Prices
    an offer needs are created and verified by MotionGrove when the customer
    accepts it. Nothing here can write or show a secret.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Private Offers')

@section('content')
    <section id="admin-private-offers">

        @if (session('message'))
            <div class="alert alert-{{ session('status') === 'error' ? 'danger' : 'success' }}" role="status">{{ session('message') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="card mb-2" data-role="create-offer">
            <div class="card-header"><h4 class="card-title">Create offer</h4></div>
            <div class="card-body">
                <p class="text-muted">
                    Give one customer special terms. You choose the plan, an optional free trial, a negotiated
                    recurring price and/or a discount; MotionGrove creates the unique link. The customer must
                    review and accept the exact terms before they reach Stripe Checkout, and a free trial starts
                    only once Stripe confirms Checkout.
                </p>

                <form method="POST" action="{{ route('admin.platform-billing.offers.store') }}">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-1">
                            <label class="form-label" for="offer_kind">What is this?</label>
                            <select class="form-select" id="offer_kind" name="kind">
                                <option value="new_subscription" @selected(old('kind', $prefillWorkspace ? 'billing_change' : 'new_subscription') === 'new_subscription')>
                                    A new subscription (customer pays through Stripe Checkout)
                                </option>
                                <option value="billing_change" @selected(old('kind', $prefillWorkspace ? 'billing_change' : 'new_subscription') === 'billing_change')>
                                    New pricing for an existing subscriber (needs a Workspace)
                                </option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-1">
                            <label class="form-label" for="offer_tier">Plan</label>
                            <select class="form-select" id="offer_tier" name="tier">
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->tier->value }}" @selected(old('tier') === $plan->tier->value)>
                                        {{ $plan->display_name }}
                                        @if ($plan->price !== null) — {{ $plan->price }} {{ $plan->currency?->code }} · {{ \App\Library\PlatformBilling\BillingCycle::label((string) $plan->billing_cycle) }}@endif
                                    </option>
                                @endforeach
                            </select>
                            <small class="text-muted">For an existing subscriber the plan stays whatever they are on.</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-1">
                            <label class="form-label" for="offer_email">Customer email</label>
                            <input class="form-control" id="offer_email" name="customer_email" type="email" value="{{ old('customer_email') }}" placeholder="name@company.com">
                            <small class="text-muted">The customer must sign in with this <em>verified</em> email to use the link.</small>
                        </div>
                        <div class="col-md-6 mb-1">
                            <label class="form-label" for="offer_workspace">…or an existing Workspace (uid)</label>
                            <input class="form-control" id="offer_workspace" name="workspace_uid" type="text" value="{{ old('workspace_uid', $prefillWorkspace?->uid) }}" placeholder="Workspace uid">
                            @if ($prefillWorkspace)<small class="text-muted">{{ $prefillWorkspace->name }}</small>@endif
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="offer_trial">Free trial (days)</label>
                            <input class="form-control" id="offer_trial" name="trial_days" type="number" min="1" max="730" value="{{ old('trial_days') }}" list="trial-presets" placeholder="none">
                            <datalist id="trial-presets"><option value="7"><option value="14"><option value="28"></datalist>
                            <small class="text-muted">Any length. The card is collected up front so it renews automatically.</small>
                        </div>
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="offer_price">Custom recurring price</label>
                            <input class="form-control" id="offer_price" name="custom_price" type="text" inputmode="decimal" value="{{ old('custom_price') }}" placeholder="plan price">
                            <small class="text-muted">A <strong>permanent</strong> negotiated price, every 28 days. Leave empty to keep the plan's catalog price.</small>
                        </div>
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="offer_limit">Redemption limit</label>
                            <input class="form-control" id="offer_limit" name="redemption_limit" type="number" min="1" max="50" value="{{ old('redemption_limit', 1) }}">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="offer_discount_type">Discount</label>
                            <select class="form-select" id="offer_discount_type" name="discount_type">
                                <option value="none" @selected(old('discount_type', 'none') === 'none')>None</option>
                                <option value="fixed" @selected(old('discount_type') === 'fixed')>Fixed amount off</option>
                                <option value="percent" @selected(old('discount_type') === 'percent')>Percentage off</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="offer_discount_value">Amount / percent</label>
                            <input class="form-control" id="offer_discount_value" name="discount_value" type="text" inputmode="decimal" value="{{ old('discount_value') }}">
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="offer_discount_duration">The discount lasts</label>
                            <select class="form-select" id="offer_discount_duration" name="discount_duration">
                                <option value="forever" @selected(old('discount_duration', 'forever') === 'forever')>Forever</option>
                                <option value="once" @selected(old('discount_duration') === 'once')>One billing period</option>
                                <option value="periods" @selected(old('discount_duration') === 'periods')>A number of 28-day billing periods</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="offer_discount_periods">Number of periods</label>
                            <input class="form-control" id="offer_discount_periods" name="discount_periods" type="number" min="2" max="120" value="{{ old('discount_periods') }}">
                        </div>
                    </div>
                    <p class="text-muted">
                        A discount that is not "forever" is a <strong>temporary promotion</strong>: when it ends the price automatically
                        reverts to the permanent negotiated price above, or — if you set none — to the plan's catalog price.
                    </p>

                    <div class="row">
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="offer_expires">Link expires</label>
                            <input class="form-control" id="offer_expires" name="expires_at" type="date" value="{{ old('expires_at', now()->addDays(14)->toDateString()) }}" required>
                        </div>
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="offer_label">Internal name (optional)</label>
                            <input class="form-control" id="offer_label" name="label" type="text" maxlength="120" value="{{ old('label') }}">
                        </div>
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="offer_reason">Reason</label>
                            <input class="form-control" id="offer_reason" name="reason" type="text" value="{{ old('reason') }}" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Create offer</button>
                </form>
            </div>
        </div>

        <div class="card" data-role="offers-list">
            <div class="card-header">
                <h4 class="card-title">Offers @if ($prefillWorkspace) for {{ $prefillWorkspace->name }} @endif</h4>
            </div>
            <div class="card-body">
                @if (count($rows) === 0)
                    <p class="mb-0" data-role="offers-empty">No offers yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table" data-role="offers">
                            <thead>
                            <tr><th>Offer</th><th>Plan</th><th>For</th><th>Terms</th><th>Status</th><th>Redeemed</th><th>Expires</th></tr>
                            </thead>
                            <tbody>
                            @foreach ($rows as $row)
                                @php($o = $row['offer'])
                                <tr>
                                    <td><a href="{{ route('admin.platform-billing.offers.show', [$o->uid]) }}">{{ $o->label }}</a>
                                        @if ($o->isBillingChange())<span class="badge bg-light-info">billing change</span>@endif</td>
                                    <td>{{ $o->catalog?->display_name }}</td>
                                    <td>{{ $o->customer_email ?? $o->workspace?->name }}</td>
                                    <td>{{ $row['summary'] }}</td>
                                    <td data-role="offer-status">{{ ucfirst($row['status']) }}</td>
                                    <td>{{ $row['redeemed'] }} / {{ $o->redemption_limit }}@if ($row['in_progress'] > 0) <span class="text-muted">({{ $row['in_progress'] }} in checkout)</span>@endif</td>
                                    <td>{{ $o->expires_at->toFormattedDateString() }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
