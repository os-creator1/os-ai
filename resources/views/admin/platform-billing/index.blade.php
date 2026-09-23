{{--
    Implementation Contract 21 §11 — Platform Owner lane-A commercial controls
    and Billing & Revenue.

    NO SECRET IS RENDERED HERE (§5.1). The Stripe API key and the lane-A
    webhook signing secret stay in secure runtime configuration; this page
    shows Configured / Missing, the mode, and the endpoint URL to paste into
    Stripe. There is no input on this page that could write a secret into the
    database.

    LANE A ONLY. Business revenue (lane B), Agency revenue (lane C) and usage
    funding (lane D) are never counted here as platform SaaS revenue.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Platform Billing')

@section('content')
    <section id="admin-platform-billing">

        @if (session('message'))
            <div class="alert alert-{{ session('status') === 'error' ? 'danger' : 'success' }}" role="status">
                {{ session('message') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        {{-- ---------------------------------------------------------------
             Stripe configuration status — booleans and a mode word only.
        ---------------------------------------------------------------- --}}
        <div class="card mb-2">
            <div class="card-header"><h4 class="card-title">Stripe (Lane A — platform subscriptions)</h4></div>
            <div class="card-body">
                <dl class="row mb-0" data-role="provider-status">
                    <dt class="col-sm-4">API key</dt>
                    <dd class="col-sm-8" data-role="provider-configured">
                        {{ $provider['configured'] ? 'Configured' : 'Missing' }}
                    </dd>

                    <dt class="col-sm-4">Mode</dt>
                    <dd class="col-sm-8" data-role="provider-mode">{{ $provider['mode'] }}</dd>

                    <dt class="col-sm-4">Webhook signing secret</dt>
                    <dd class="col-sm-8" data-role="provider-webhook-configured">
                        {{ $provider['webhook_configured'] ? 'Configured' : 'Missing' }}
                    </dd>

                    <dt class="col-sm-4">Webhook endpoint</dt>
                    <dd class="col-sm-8"><code data-role="provider-webhook-url">{{ $provider['webhook_url'] }}</code></dd>
                </dl>

                <hr>
                <p class="mb-1"><strong>Operator setup</strong></p>
                <ol class="mb-0">
                    <li>Set <code>STRIPE_SECRET</code> and <code>STRIPE_KEY</code> in the deployment environment.</li>
                    <li>In Stripe → Developers → Webhooks, add an endpoint at the URL above.</li>
                    <li>Subscribe it to exactly: <code>checkout.session.completed</code>,
                        <code>customer.subscription.created</code>, <code>customer.subscription.updated</code>,
                        <code>customer.subscription.deleted</code>, <code>invoice.paid</code>,
                        <code>invoice.payment_failed</code>.</li>
                    <li>Put that endpoint's signing secret in
                        <code>STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET</code>. It is deliberately
                        different from lane B's and lane D's.</li>
                </ol>
                <p class="text-muted mt-1 mb-0">
                    Secrets are never stored in the database and are never displayed here.
                </p>
            </div>
        </div>

        {{-- ---------------------------------------------------------------
             Commercial configuration, per tier.
        ---------------------------------------------------------------- --}}
        @foreach ($plans as $plan)
            <div class="card mb-2" data-role="plan-{{ $plan['tier_value'] }}">
                <div class="card-header">
                    <h4 class="card-title">{{ $plan['display_name'] }}</h4>
                    <span data-role="sellable-{{ $plan['tier_value'] }}">
                        {{ $plan['sellable'] ? 'On sale' : 'Not on sale' }}
                    </span>
                </div>
                <div class="card-body">
                    @if (count($plan['blockers']) > 0)
                        <ul data-role="blockers-{{ $plan['tier_value'] }}">
                            @foreach ($plan['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach
                        </ul>
                    @endif

                    <form method="POST" action="{{ route('admin.platform-billing.update', [$plan['tier_value']]) }}">
                        @csrf

                        <label for="price_{{ $plan['tier_value'] }}">Price</label>
                        <input id="price_{{ $plan['tier_value'] }}" name="price" type="text"
                               value="{{ old('price', $plan['price']) }}" required>

                        <label for="currency_{{ $plan['tier_value'] }}">Currency</label>
                        <select id="currency_{{ $plan['tier_value'] }}" name="currency_id" required>
                            @foreach ($currencies as $currency)
                                <option value="{{ $currency->id }}" @selected((int) $plan['currency_id'] === (int) $currency->id)>
                                    {{ $currency->code }}
                                </option>
                            @endforeach
                        </select>

                        <label for="cycle_{{ $plan['tier_value'] }}">Billing cycle</label>
                        <select id="cycle_{{ $plan['tier_value'] }}" name="billing_cycle" required>
                            @foreach (['monthly', 'yearly'] as $cycle)
                                <option value="{{ $cycle }}" @selected($plan['billing_cycle'] === $cycle)>{{ $cycle }}</option>
                            @endforeach
                        </select>

                        <label>
                            <input type="checkbox" name="trial_enabled" value="1" @checked($plan['trial_enabled'])>
                            Offer a free trial
                        </label>

                        <label for="trial_days_{{ $plan['tier_value'] }}">Trial length (days)</label>
                        <input id="trial_days_{{ $plan['tier_value'] }}" name="trial_days" type="number" min="1" max="730"
                               value="{{ old('trial_days', $plan['trial_days']) }}">

                        <label>
                            <input type="checkbox" name="available_for_signup" value="1" @checked($plan['available_for_signup'])>
                            Available to new signups
                        </label>

                        <label for="price_id_{{ $plan['tier_value'] }}">Stripe Price ID</label>
                        <input id="price_id_{{ $plan['tier_value'] }}" name="provider_price_id" type="text"
                               value="{{ old('provider_price_id', $plan['provider_price_id']) }}"
                               placeholder="price_...">
                        <small>
                            Create a recurring Price on <strong>this platform's own Stripe account</strong> for this
                            amount and interval, then paste its id. A Stripe Price is immutable, so changing the
                            amount means creating a NEW Price and entering the new id here. Existing subscribers keep
                            the price they were sold on — repricing never moves them.
                            Never paste a Price from a connected account (a Business's or an Agency's): that is a
                            different money lane.
                        </small>

                        <label for="reason_{{ $plan['tier_value'] }}">Reason for this change</label>
                        <input id="reason_{{ $plan['tier_value'] }}" name="reason" type="text" required>

                        <button type="submit">Save {{ $plan['display_name'] }}</button>
                    </form>
                </div>
            </div>
        @endforeach

        {{-- ---------------------------------------------------------------
             Billing & Revenue — lane A only.
        ---------------------------------------------------------------- --}}
        <div class="card mb-2">
            <div class="card-header"><h4 class="card-title">Lane A subscriptions</h4></div>
            <div class="card-body">
                <dl class="row" data-role="metrics">
                    @foreach ($metrics as $key => $value)
                        <dt class="col-sm-4">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt>
                        <dd class="col-sm-8" data-role="metric-{{ $key }}">{{ $value }}</dd>
                    @endforeach
                </dl>
                <p class="text-muted mb-0">
                    Platform SaaS subscription revenue only. Business revenue, Agency revenue and usage funding are
                    separate money lanes and are not counted here.
                </p>
            </div>
        </div>

        <div class="card mb-2">
            <div class="card-header"><h4 class="card-title">Needs attention</h4></div>
            <div class="card-body">
                @if (count($attention) === 0)
                    <p class="mb-0" data-role="attention-empty">No failed payments.</p>
                @else
                    <table data-role="attention">
                        <thead><tr><th>Account</th><th>State</th><th>Amount</th><th>Grace started</th><th>Locked</th></tr></thead>
                        <tbody>
                        @foreach ($attention as $row)
                            <tr>
                                <td>{{ $row['workspace_name'] }}</td>
                                <td>{{ $row['status'] }}</td>
                                <td>{{ $row['price_snapshot'] }} {{ $row['currency_code'] }}</td>
                                <td>{{ $row['grace_started_at'] }}</td>
                                <td>{{ $row['locked_at'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="card mb-2">
            <div class="card-header"><h4 class="card-title">Subscriptions</h4></div>
            <div class="card-body">
                <table data-role="subscriptions">
                    <thead>
                    <tr><th>Account</th><th>Tier</th><th>State</th><th>Price</th><th>Trial ends</th><th>Period ends</th><th>Cancelling</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($subscriptions as $row)
                        <tr>
                            <td>{{ $row['workspace_name'] }}</td>
                            <td>{{ $row['tier_name'] }}</td>
                            <td>{{ $row['status'] }}</td>
                            <td>{{ $row['price_snapshot'] }} {{ $row['currency_code'] }} / {{ $row['billing_cycle_snapshot'] }}</td>
                            <td>{{ $row['trial_ends_at'] }}</td>
                            <td>{{ $row['current_period_end'] }}</td>
                            <td>{{ $row['cancel_at_period_end'] ? 'yes' : 'no' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h4 class="card-title">Webhook health</h4></div>
            <div class="card-body">
                <dl class="row mb-0" data-role="webhook-health">
                    <dt class="col-sm-4">Events received</dt>
                    <dd class="col-sm-8" data-role="webhook-total">{{ $webhook['total'] }}</dd>
                    <dt class="col-sm-4">Failed</dt>
                    <dd class="col-sm-8" data-role="webhook-failed">{{ $webhook['failed'] }}</dd>
                    <dt class="col-sm-4">Most recent</dt>
                    <dd class="col-sm-8" data-role="webhook-latest">
                        {{ $webhook['latest_type'] ?? 'none yet' }}
                        @if ($webhook['latest_at']) — {{ $webhook['latest_at'] }} ({{ $webhook['latest_state'] }}) @endif
                    </dd>
                </dl>
            </div>
        </div>
    </section>
@endsection
