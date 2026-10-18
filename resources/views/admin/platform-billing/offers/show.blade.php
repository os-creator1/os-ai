@extends('layouts/contentLayoutMaster')

@section('title', $offer->label)

@section('content')
    <section id="admin-private-offer">

        @if (session('message'))
            <div class="alert alert-{{ session('status') === 'error' ? 'danger' : 'success' }}" role="status">{{ session('message') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        {{-- The link exists in plain text ONLY in this one flash. --}}
        @if (session('offer_link'))
            <div class="card mb-2 border-primary" data-role="offer-link-card">
                <div class="card-body">
                    <h4 class="card-title">Offer link</h4>
                    <div class="input-group">
                        <input class="form-control" id="offer-link" data-role="offer-link" type="text" readonly value="{{ session('offer_link') }}">
                        <button class="btn btn-primary" type="button" id="copy-offer-link" data-role="copy-offer-link">Copy link</button>
                    </div>
                    <small class="text-muted">Shown once. If you lose it, use “New link” below — the old one then stops working.</small>
                </div>
            </div>
        @endif

        <div class="card mb-2">
            <div class="card-header">
                <h4 class="card-title">{{ $offer->label }}</h4>
                <span class="badge bg-light-{{ $offer->status() === 'active' ? 'success' : 'secondary' }}" data-role="offer-status">{{ ucfirst($offer->status()) }}</span>
            </div>
            <div class="card-body">
                <dl class="row mb-0" data-role="offer-details">
                    <dt class="col-sm-3">Type</dt>
                    <dd class="col-sm-9">{{ $offer->isBillingChange() ? 'New pricing for an existing subscriber' : 'New subscription' }}</dd>
                    <dt class="col-sm-3">Plan</dt>
                    <dd class="col-sm-9">{{ $offer->catalog?->display_name }} · {{ \App\Library\PlatformBilling\BillingCycle::label((string) $offer->catalog?->billing_cycle) }}</dd>
                    <dt class="col-sm-3">For</dt>
                    <dd class="col-sm-9">{{ $offer->customer_email }} @if ($offer->workspace) · Workspace <a href="{{ route('admin.workspaces.show', [$offer->workspace->uid]) }}">{{ $offer->workspace->name }}</a> @endif</dd>
                    <dt class="col-sm-3">Terms</dt>
                    <dd class="col-sm-9" data-role="offer-summary">{{ $summary }}</dd>
                    <dt class="col-sm-3">Expires</dt>
                    <dd class="col-sm-9">{{ $offer->expires_at->toDayDateTimeString() }}</dd>
                    <dt class="col-sm-3">Redemption limit</dt>
                    <dd class="col-sm-9">{{ $offer->redemption_limit }}</dd>
                    <dt class="col-sm-3">Created</dt>
                    <dd class="col-sm-9">{{ $offer->created_at->toDayDateTimeString() }} by {{ $offer->creator?->email }} — “{{ $offer->created_reason }}”</dd>
                    @if ($offer->isRevoked())
                        <dt class="col-sm-3">Revoked</dt>
                        <dd class="col-sm-9">{{ $offer->revoked_at->toDayDateTimeString() }} — “{{ $offer->revoked_reason }}”</dd>
                    @endif
                </dl>
            </div>
        </div>

        <div class="card mb-2" data-role="redemptions">
            <div class="card-header"><h4 class="card-title">Redemptions</h4></div>
            <div class="card-body">
                @if ($redemptions->isEmpty())
                    <p class="mb-0" data-role="redemptions-empty">Nobody has accepted this offer yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>Workspace</th><th>Accepted by</th><th>State</th><th>Accepted</th><th>Redeemed</th><th>Promotion</th><th>Reconciliation</th></tr></thead>
                            <tbody>
                            @foreach ($redemptions as $r)
                                <tr>
                                    <td>{{ $r->workspace?->name }}</td>
                                    <td>{{ $r->accepted_email }}</td>
                                    <td data-role="redemption-state">{{ $r->state }}@if ($r->release_reason) ({{ $r->release_reason }})@endif</td>
                                    <td>{{ $r->accepted_at->toDayDateTimeString() }}</td>
                                    <td>{{ $r->redeemed_at?->toDayDateTimeString() ?? '—' }}</td>
                                    <td>
                                        @if ($r->promo_ends_at) ends {{ $r->promo_ends_at->toFormattedDateString() }} ({{ $r->schedule_state }}) @else — @endif
                                    </td>
                                    <td>{{ $r->reconcile_state }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        @unless ($offer->isRevoked())
            <div class="card mb-2" data-role="offer-actions">
                <div class="card-header"><h4 class="card-title">Manage</h4></div>
                <div class="card-body">
                    @if ($canApplyWithoutAcceptance)
                        <form method="POST" class="mb-2" action="{{ route('admin.platform-billing.offers.apply', [$offer->uid]) }}" data-role="apply-change">
                            @csrf
                            <p class="mb-50">This change can never cost the customer more than they pay today, so you can apply it now without waiting for them. It starts at their next renewal; nothing is charged today.</p>
                            <input class="form-control mb-50" name="reason" type="text" placeholder="Reason" required>
                            <button class="btn btn-primary" type="submit">Apply to the subscription now</button>
                        </form>
                    @endif

                    <form method="POST" class="mb-2" action="{{ route('admin.platform-billing.offers.new-link', [$offer->uid]) }}" data-role="new-link">
                        @csrf
                        <input class="form-control mb-50" name="reason" type="text" placeholder="Reason" required>
                        <button class="btn btn-outline-primary" type="submit">New link (the old one stops working)</button>
                    </form>

                    <form method="POST" action="{{ route('admin.platform-billing.offers.revoke', [$offer->uid]) }}" data-role="revoke">
                        @csrf
                        <p class="mb-50 text-muted">Revoking stops anyone who has not paid yet. Customers who already redeemed keep the terms they accepted.</p>
                        <input class="form-control mb-50" name="reason" type="text" placeholder="Reason" required>
                        <button class="btn btn-outline-danger" type="submit">Revoke offer</button>
                    </form>
                </div>
            </div>
        @endunless
    </section>

    <script>
        (function () {
            var button = document.getElementById('copy-offer-link');
            var field = document.getElementById('offer-link');
            if (!button || !field) { return; }
            button.addEventListener('click', function () {
                field.select();
                var done = function () { button.textContent = 'Copied'; };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(field.value).then(done, function () { document.execCommand('copy'); done(); });
                } else {
                    document.execCommand('copy'); done();
                }
            });
        })();
    </script>
@endsection
