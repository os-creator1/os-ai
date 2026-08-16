@extends('layouts/contentLayoutMaster')

@section('title', 'Usage & Billing')

@section('content')
    @php
        // Presentation-only formatting of the presenter's own integer
        // micro-unit strings — never a balance/cap/entitlement
        // recomputation (M2 contract §9/§10). Defined locally in this
        // view rather than as a new global helper file, since no such
        // path is authorized by the M2 allowlist.
        $money = static function (?string $micro, string $currencyCode): string {
            if ($micro === null) {
                return '—';
            }

            $major = bcdiv($micro, '1000000', 2);

            return ($currencyCode !== '' ? $currencyCode . ' ' : '') . number_format((float) $major, 2);
        };
    @endphp

    <section id="usage-billing-dashboard">
        <div class="row">
            <div class="col-12">
                <a href="{{ route('customer.workspaces.show', $workspaceUid) }}">Back to Workspace</a>
            </div>

            <div class="col-12">
                @if (session('flash_success'))
                    <div class="alert alert-success">{{ session('flash_success') }}</div>
                @endif

                @if (session('flash_error'))
                    <div class="alert alert-danger">{{ session('flash_error') }}</div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ $dashboard->business['name'] }} &mdash; Usage &amp; Billing</h4>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-3">Billing status</dt>
                            <dd class="col-sm-9">
                                @if ($dashboard->business['billing_status'] === 'suspended')
                                    <span class="badge badge-light-danger">Suspended</span>
                                    <div class="text-muted small mt-1">This Business's usage wallet is suspended. Contact support.</div>
                                @else
                                    <span class="badge badge-light-success">Active</span>
                                @endif
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card" id="usage-billing-wallet">
                    <div class="card-header">
                        <h4 class="card-title">Balance &amp; Spend</h4>
                    </div>
                    <div class="card-body">
                        @if ($dashboard->wallet === null)
                            <p class="mb-0">Usage tracking has not been set up for this Business yet.</p>
                        @else
                            <dl class="row mb-0">
                                <dt class="col-sm-4">Available balance</dt>
                                <dd class="col-sm-8">{{ $money($dashboard->wallet['available_balance_micro'], $dashboard->wallet['currency_code']) }}</dd>

                                <dt class="col-sm-4">Reserved balance</dt>
                                <dd class="col-sm-8">{{ $money($dashboard->wallet['reserved_balance_micro'], $dashboard->wallet['currency_code']) }}</dd>

                                <dt class="col-sm-4">Debt balance</dt>
                                <dd class="col-sm-8">
                                    {{ $money($dashboard->wallet['debt_balance_micro'], $dashboard->wallet['currency_code']) }}
                                    @if ((int) $dashboard->wallet['debt_balance_micro'] > 0)
                                        <span class="badge badge-light-danger ms-1">Outstanding debt</span>
                                    @endif
                                </dd>

                                <dt class="col-sm-4">Current period</dt>
                                <dd class="col-sm-8">{{ $dashboard->wallet['spend_period_key'] }}</dd>

                                <dt class="col-sm-4">Committed spend this period</dt>
                                <dd class="col-sm-8">{{ $money($dashboard->wallet['committed_spend_this_period_micro'], $dashboard->wallet['currency_code']) }}</dd>

                                <dt class="col-sm-4">Monthly spend cap</dt>
                                <dd class="col-sm-8">
                                    @if ($dashboard->wallet['monthly_spend_cap_micro'] === null)
                                        No spend cap configured
                                    @else
                                        {{ $money($dashboard->wallet['monthly_spend_cap_micro'], $dashboard->wallet['currency_code']) }}
                                        &mdash; {{ $money($dashboard->wallet['remaining_headroom_micro'], $dashboard->wallet['currency_code']) }} remaining
                                    @endif
                                </dd>
                            </dl>

                            <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.spend-cap', [$workspaceUid, $businessUid]) }}" class="mt-2">
                                @csrf

                                <div class="mb-1">
                                    <label class="form-label" for="usage-billing-spend-cap">Monthly spend cap ({{ $dashboard->wallet['currency_code'] }}, leave blank for no cap)</label>
                                    <input type="number" step="0.01" min="0" class="form-control" id="usage-billing-spend-cap" name="monthly_spend_cap_micro_display" value="{{ old('monthly_spend_cap_micro_display', $dashboard->wallet['monthly_spend_cap_micro'] !== null ? bcdiv($dashboard->wallet['monthly_spend_cap_micro'], '1000000', 2) : '') }}">
                                    <input type="hidden" name="monthly_spend_cap_micro" id="usage-billing-spend-cap-micro">
                                </div>

                                <button type="submit" class="btn btn-outline-primary">Update spend cap</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card" id="usage-billing-feature-limits">
                    <div class="card-header">
                        <h4 class="card-title">Per-feature usage limits</h4>
                    </div>
                    <div class="card-body">
                        @if (empty($dashboard->featureLimits))
                            <p>No per-feature limits are configured yet.</p>
                        @else
                            <div class="table-responsive mb-2">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Feature</th>
                                            <th>Configured limit</th>
                                            <th>Platform ceiling</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($dashboard->featureLimits as $limit)
                                            <tr>
                                                <td>{{ $limit['feature_key'] }}</td>
                                                <td>{{ $limit['monthly_limit_micro'] !== null ? $money($limit['monthly_limit_micro'], $dashboard->wallet['currency_code'] ?? '') : 'No limit configured' }}</td>
                                                <td>{{ $limit['safety_limit_micro'] !== null ? $money($limit['safety_limit_micro'], $dashboard->wallet['currency_code'] ?? '') : 'Not configured' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <form method="POST" id="usage-billing-feature-limit-form" class="row g-1 align-items-end">
                            @csrf

                            <div class="col-auto">
                                <label class="form-label" for="usage-billing-feature-key">Feature key</label>
                                <input type="text" class="form-control" id="usage-billing-feature-key" name="feature_key_display" placeholder="e.g. crm">
                            </div>

                            <div class="col-auto">
                                <label class="form-label" for="usage-billing-feature-limit">Monthly limit (leave blank for no limit)</label>
                                <input type="number" step="0.01" min="0" class="form-control" id="usage-billing-feature-limit" name="monthly_limit_micro_display">
                                <input type="hidden" name="monthly_limit_micro" id="usage-billing-feature-limit-micro">
                            </div>

                            <div class="col-auto">
                                <button type="submit" class="btn btn-outline-primary">Update feature limit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card" id="usage-billing-payer">
                    <div class="card-header">
                        <h4 class="card-title">Payer</h4>
                    </div>
                    <div class="card-body">
                        <p>
                            @if ($dashboard->payer === null)
                                Not yet configured.
                            @elseif ($dashboard->payer['payer_type'] === 'workspace')
                                This Business's usage is billed to the Workspace.
                            @else
                                This Business pays for its own usage.
                            @endif
                        </p>

                        <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.payer', [$workspaceUid, $businessUid]) }}" class="d-flex align-items-end">
                            @csrf

                            <div class="me-1">
                                <label class="form-label" for="usage-billing-payer-type">Set payer</label>
                                <select class="form-control" id="usage-billing-payer-type" name="payer_type">
                                    <option value="workspace" @selected(($dashboard->payer['payer_type'] ?? null) === 'workspace')>Workspace pays</option>
                                    <option value="business" @selected(($dashboard->payer['payer_type'] ?? null) === 'business')>Business pays</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-outline-primary">Update payer</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card" id="usage-billing-contact">
                    <div class="card-header">
                        <h4 class="card-title">Billing contact</h4>
                    </div>
                    <div class="card-body">
                        @if ($dashboard->billingContact === null)
                            <p>No billing contact configured.</p>
                        @else
                            <dl class="row">
                                <dt class="col-sm-3">Name</dt>
                                <dd class="col-sm-9">{{ $dashboard->billingContact['name'] }}</dd>

                                <dt class="col-sm-3">Email</dt>
                                <dd class="col-sm-9">{{ $dashboard->billingContact['email'] }}</dd>

                                <dt class="col-sm-3">Notifications</dt>
                                <dd class="col-sm-9">{{ $dashboard->billingContact['notification_opt_in'] ? 'Enabled' : 'Disabled' }}</dd>
                            </dl>
                        @endif

                        <form method="POST" action="{{ route('customer.workspaces.businesses.usage-billing.billing-contact', [$workspaceUid, $businessUid]) }}">
                            @csrf

                            <div class="mb-1">
                                <label class="form-label" for="usage-billing-contact-name">Contact name</label>
                                <input type="text" class="form-control" id="usage-billing-contact-name" name="contact_name" value="{{ old('contact_name', $dashboard->billingContact['name'] ?? '') }}">
                            </div>

                            <div class="mb-1">
                                <label class="form-label" for="usage-billing-contact-email">Contact email</label>
                                <input type="email" class="form-control" id="usage-billing-contact-email" name="contact_email" value="{{ old('contact_email', $dashboard->billingContact['email'] ?? '') }}">
                            </div>

                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="notification_opt_in" id="usage-billing-contact-notify" value="1" @checked(old('notification_opt_in', $dashboard->billingContact['notification_opt_in'] ?? true))>
                                <label class="form-check-label" for="usage-billing-contact-notify">Send billing notifications to this contact</label>
                            </div>

                            <button type="submit" class="btn btn-outline-primary">Update billing contact</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card" id="usage-billing-payment-methods">
                    <div class="card-header">
                        <h4 class="card-title">Payment methods</h4>
                    </div>
                    <div class="card-body">
                        <p class="mb-0 text-muted">Payment methods and top-ups are not yet configured.</p>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card" id="usage-billing-ledger">
                    <div class="card-header">
                        <h4 class="card-title">Recent usage activity</h4>
                    </div>
                    <div class="card-body">
                        @if ($dashboard->ledger->isEmpty())
                            <p class="mb-0">No usage activity yet.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Feature</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($dashboard->ledger as $entry)
                                            <tr>
                                                <td>{{ $entry->createdAt->format('Y-m-d H:i') }}</td>
                                                <td>{{ $entry->entryType }}</td>
                                                <td>{{ $entry->featureKey ?? '—' }}</td>
                                                <td>{{ $money($entry->signedAmountMicro, $dashboard->wallet['currency_code'] ?? '') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-2">
                                {{ $dashboard->ledger->links() }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        (function () {
            var spendCapForm = document.getElementById('usage-billing-spend-cap');

            if (spendCapForm) {
                spendCapForm.closest('form').addEventListener('submit', function () {
                    var display = document.getElementById('usage-billing-spend-cap').value;
                    document.getElementById('usage-billing-spend-cap-micro').value = display === '' ? '' : Math.round(parseFloat(display) * 1000000);
                });
            }

            var featureLimitForm = document.getElementById('usage-billing-feature-limit-form');

            if (featureLimitForm) {
                featureLimitForm.addEventListener('submit', function (event) {
                    var featureKey = document.getElementById('usage-billing-feature-key').value.trim();
                    var display = document.getElementById('usage-billing-feature-limit').value;

                    if (! featureKey) {
                        event.preventDefault();
                        return;
                    }

                    document.getElementById('usage-billing-feature-limit-micro').value = display === '' ? '' : Math.round(parseFloat(display) * 1000000);

                    var basePath = window.location.pathname.replace(/\/+$/, '');
                    featureLimitForm.setAttribute('action', basePath + '/feature-limits/' + encodeURIComponent(featureKey));
                });
            }
        })();
    </script>
@endsection
