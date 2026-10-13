@extends('layouts/contentLayoutMaster')

@section('title', 'Delete customer account')

@section('content')
    <section id="admin-platform-user-delete">
        @include('admin.partials.flash')

        @php($isDelete = $account['verdict'] === 'delete')

        <div class="card border-danger mb-2">
            <div class="card-header">
                <h4 class="card-title text-danger">{{ $isDelete ? 'Delete account' : 'Close account' }}: {{ $account['user']['email'] }}</h4>
            </div>
            <div class="card-body">
                @if ($account['verdict'] === 'blocked')
                    <div class="alert alert-danger" role="alert"><div class="alert-body"><strong>Deletion blocked.</strong> {{ implode(' ', $account['reasons']) }}</div></div>
                @elseif ($isDelete)
                    <div class="alert alert-warning" role="status"><div class="alert-body">
                        <strong>Complete deletion is possible.</strong> This account has no financial, provider or audit history that must be kept. Everything listed below is permanently erased.
                    </div></div>
                @else
                    <div class="alert alert-warning" role="status"><div class="alert-body">
                        <strong>Complete deletion is not possible; the account will be closed.</strong>
                        Access, billing and activity stop, but these records must be retained:
                        <ul class="mb-0">
                            @foreach ($account['protected_records'] as $r)<li>{{ $r['count'] }} × {{ $r['label'] }}</li>@endforeach
                            @foreach ($account['external_references'] as $r)<li>{{ $r['count'] }} record(s) in <code>{{ $r['table'] }}</code> belong to other owners or are shared</li>@endforeach
                            @if ($account['connected_services']['carrier_numbers'])<li>{{ $account['connected_services']['carrier_numbers'] }} messaging number(s) still held at the carrier</li>@endif
                        </ul>
                    </div></div>
                @endif

                <h5>Who is affected</h5>
                <p><strong>{{ $account['user']['name'] ?: $account['user']['email'] }}</strong> ({{ $account['user']['email'] }}), joined {{ $account['user']['created_at'] }}. Their sign-in, sessions and tokens are revoked immediately.</p>

                <h5>Owned Workspaces — {{ count($account['owned_workspaces']) }}</h5>
                @forelse ($account['owned_workspaces'] as $w)
                    <div class="border rounded p-1 mb-1">
                        <strong>{{ $w['name'] }}</strong> <span class="text-muted small">plan {{ $w['plan'] ?? 'none' }}</span>
                        <div class="small">Businesses:
                            @forelse ($w['businesses'] as $b){{ $b['name'] }} ({{ $b['status'] }}; {{ count($b['locations']) }} location(s))@unless ($loop->last), @endunless
                            @empty none @endforelse
                        </div>
                        @if ($w['members_losing_access'])
                            <div class="small text-danger">Other members who lose access: {{ collect($w['members_losing_access'])->pluck('email')->implode(', ') }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-muted">None.</p>
                @endforelse

                @if ($account['memberships_elsewhere'])
                    <h5>Membership only (nothing here is deleted)</h5>
                    <p class="small">This user only loses their seat in: {{ collect($account['memberships_elsewhere'])->map(fn ($m) => $m['workspace'] . ' (owned by ' . $m['owner_email'] . ')')->implode('; ') }}.</p>
                @endif

                @if ($account['agency']['clients'])
                    <h5>Agency clients — kept</h5>
                    <p class="small">The Agency relationship ends, but these independently owned Workspaces are <strong>not</strong> deleted: {{ collect($account['agency']['clients'])->map(fn ($c) => $c['workspace'] . ' (' . $c['owner_email'] . ')')->implode('; ') }}.</p>
                @endif

                <h5>Billing commitments that will be cancelled</h5>
                <ul>
                    @foreach ($account['billing_commitments']['platform'] as $p)<li>Platform subscription ({{ $p['status'] }}) — cancelled at the payment provider now, not just locally.</li>@endforeach
                    @foreach ($account['billing_commitments']['agency_as_client'] as $p)<li>Subscription paid to an Agency ({{ $p['status'] }}) — cancelled on the Agency's Stripe account.</li>@endforeach
                    @foreach ($account['billing_commitments']['agency_as_agency'] as $p)<li>Client subscription billed through this Agency's Stripe account ({{ $p['status'] }}) — cancelled so the client is not charged a closed Agency.</li>@endforeach
                    @foreach ($account['billing_commitments']['slot_agreements'] as $p)<li>Additional-Business slot agreement ({{ $p['state'] }}) — renewal stopped.</li>@endforeach
                    @foreach ($account['billing_commitments']['wallets'] as $w)<li>Usage wallet for {{ $w['business'] }} holds a balance or debt — retained for accounting; auto-recharge is turned off.</li>@endforeach
                    @if (! $account['billing_commitments']['provider_linked'] && ! $account['billing_commitments']['wallets'])<li class="text-muted list-unstyled">None.</li>@endif
                </ul>

                <h5>Connected services and running work that will stop</h5>
                <p class="small mb-0">
                    Connections: Google {{ $account['connected_services']['google'] }}, Meta {{ $account['connected_services']['meta'] }}, email {{ $account['connected_services']['email'] }}, calendar {{ $account['connected_services']['calendar'] }}.
                    Stripe connected accounts are kept (never deleted): {{ $account['connected_services']['stripe_accounts'] }}.<br>
                    Running: {{ $account['background_work']['automations'] }} automation(s), {{ $account['background_work']['workflows'] }} workflow(s) ({{ $account['background_work']['enrollments'] }} in flight), {{ $account['background_work']['campaigns'] }} campaign(s), {{ $account['background_work']['scheduled_messages'] }} scheduled message(s), {{ $account['background_work']['outreach_campaigns'] }} outreach campaign(s), {{ $account['background_work']['published_websites'] }} published website(s).
                    Queued jobs refuse to run once the Business is inactive.
                </p>
            </div>
        </div>

        @if ($account['verdict'] !== 'blocked' && ! $account['closure'])
            <div class="card border-danger">
                <div class="card-header"><h4 class="card-title">Confirm</h4></div>
                <div class="card-body">
                    <p>Billing is cancelled with the provider first. If any cancellation fails, the account is <strong>not</strong> reported as deleted: it stays <em>Pending cancellation</em> with access blocked until you retry.</p>
                    <form method="POST" action="{{ route('admin.platform-users.destroy', $user->uid) }}" class="row g-1">
                        @csrf
                        <input type="hidden" name="fingerprint" value="{{ $account['fingerprint'] }}">
                        <div class="col-md-6">
                            <label class="form-label" for="confirm_email">Type <code>{{ $account['user']['email'] }}</code> to confirm</label>
                            <input class="form-control" id="confirm_email" name="confirm_email" autocomplete="off" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="reason">Reason (recorded in the audit trail)</label>
                            <input class="form-control" id="reason" name="reason" required minlength="3" maxlength="500" value="{{ old('reason') }}">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-danger" type="submit">{{ $isDelete ? 'Delete account' : 'Close account' }}</button>
                            <a class="btn btn-outline-secondary" href="{{ route('admin.platform-users.show', $user->uid) }}">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </section>
@endsection
