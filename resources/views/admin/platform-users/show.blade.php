@extends('layouts/contentLayoutMaster')

@section('title', 'User')

@section('content')
    <section id="admin-platform-user-show">
        @include('admin.partials.flash')

        <div class="card mb-2">
            <div class="card-header"><h4 class="card-title">{{ trim($user->first_name . ' ' . $user->last_name) ?: $user->email }}</h4></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Email</dt><dd class="col-sm-9">{{ $user->email }}</dd>
                    <dt class="col-sm-3">Account</dt>
                    <dd class="col-sm-9">
                        @if ($account['closure'])<span class="badge badge-light-danger">{{ $account['closure']['state_label'] }}</span>
                        @elseif ($user->status)<span class="badge badge-light-success">Active</span>@else<span class="badge badge-light-danger">Suspended</span>@endif
                    </dd>
                    <dt class="col-sm-3">Email verification</dt>
                    <dd class="col-sm-9">{{ $user->email_verified_at ? 'Verified ' . $user->email_verified_at->toFormattedDateString() : 'Not verified' }}</dd>
                    <dt class="col-sm-3">Joined</dt><dd class="col-sm-9">{{ $user->created_at?->toFormattedDateString() }}</dd>
                    <dt class="col-sm-3">Last active</dt><dd class="col-sm-9">{{ $user->last_access_at ? $user->last_access_at->diffForHumans() : 'Never' }}</dd>
                </dl>
            </div>
        </div>

        <div class="card mb-2" id="user-account-overview">
            <div class="card-header"><h4 class="card-title">Owned Workspaces</h4></div>
            <div class="card-body">
                @forelse ($account['owned_workspaces'] as $w)
                    <div class="border rounded p-1 mb-1">
                        <div class="d-flex justify-content-between flex-wrap">
                            <strong><a href="{{ route('admin.workspaces.show', $w['uid']) }}">{{ $w['name'] }}</a>
                                @unless ($w['is_active'])<span class="badge badge-light-secondary ms-50">Inactive</span>@endunless</strong>
                            <span class="text-muted small">
                                Plan: {{ $w['plan'] ?? 'none' }} ({{ $w['plan_status'] }})
                                @if ($w['subscription'])
                                    · Subscription: {{ $w['subscription']['status'] }}@if ($w['subscription']['cancel_at_period_end']) (ends {{ $w['subscription']['period_end'] }})@endif
                                @else
                                    · No subscription
                                @endif
                            </span>
                        </div>
                        @foreach ($w['businesses'] as $b)
                            <div class="mt-50 ps-1">
                                <a href="{{ route('admin.businesses.show', $b['uid']) }}">{{ $b['name'] }}</a>
                                <span class="badge badge-light-{{ $b['status'] === 'active' ? 'success' : 'secondary' }}">{{ $b['status'] }}</span>
                                @if ($b['locations'])
                                    <span class="text-muted small">Locations: {{ collect($b['locations'])->pluck('name')->implode(', ') }}</span>
                                @endif
                            </div>
                        @endforeach
                        @if ($w['members_losing_access'])
                            <div class="text-muted small mt-50 ps-1">Staff: {{ collect($w['members_losing_access'])->map(fn ($m) => $m['email'] . ' (' . $m['role'] . ')')->implode(', ') }}</div>
                        @endif
                    </div>
                @empty
                    <span class="text-muted">This account owns no Workspaces.</span>
                @endforelse
            </div>
        </div>

        <div class="card mb-2" id="user-memberships">
            <div class="card-header"><h4 class="card-title">Staff memberships and Agency relationships</h4></div>
            <div class="card-body">
                <h6>Member of Workspaces owned by someone else</h6>
                @forelse ($account['memberships_elsewhere'] as $m)
                    <div><a href="{{ route('admin.workspaces.show', $m['workspace_uid']) }}">{{ $m['workspace'] }}</a>
                        <span class="text-muted small">{{ $m['role'] }}, owner {{ $m['owner_email'] }}@unless ($m['active']), access removed @endunless</span></div>
                @empty
                    <p class="text-muted">None.</p>
                @endforelse
                <h6 class="mt-1">Agency</h6>
                @forelse ($account['agency']['clients'] as $c)
                    <div>Manages <a href="{{ route('admin.workspaces.show', $c['workspace_uid']) }}">{{ $c['workspace'] }}</a> <span class="text-muted small">(owned by {{ $c['owner_email'] }} — a separate account)</span></div>
                @empty
                @endforelse
                @forelse ($account['agency']['managed_by'] as $c)
                    <div>Managed by Agency <a href="{{ route('admin.workspaces.show', $c['workspace_uid']) }}">{{ $c['workspace'] }}</a> <span class="text-muted small">({{ $c['owner_email'] }})</span></div>
                @empty
                @endforelse
                @if (! $account['agency']['clients'] && ! $account['agency']['managed_by'])
                    <p class="text-muted mb-0">No Agency relationships.</p>
                @endif
            </div>
        </div>

        <div class="card mb-2" id="user-support-actions">
            <div class="card-header"><h4 class="card-title">Support actions</h4></div>
            <div class="card-body">
                <p class="text-muted">Passwords are never shown or set here. A reset is the standard emailed link, sent to the address above. Every action is recorded in the audit trail.</p>
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <form method="POST" action="{{ route('admin.platform-users.act', [$user->uid, 'password-reset']) }}">@csrf
                        <button class="btn btn-outline-primary" type="submit">Send password reset link</button></form>
                    @unless ($user->email_verified_at)
                        <form method="POST" action="{{ route('admin.platform-users.act', [$user->uid, 'resend-verification']) }}">@csrf
                            <button class="btn btn-outline-primary" type="submit">Resend verification email</button></form>
                    @endunless
                    <form method="POST" action="{{ route('admin.platform-users.act', [$user->uid, 'revoke-sessions']) }}">@csrf
                        <button class="btn btn-outline-secondary" type="submit">Sign out of all sessions</button></form>
                </div>
                <form method="POST" action="{{ route('admin.platform-users.act', [$user->uid, $user->status ? 'suspend' : 'reactivate']) }}" class="row g-1">
                    @csrf
                    <div class="col-md-8"><input class="form-control" name="reason" placeholder="Reason (recorded in the audit trail)" required minlength="3" maxlength="500" aria-label="Reason"></div>
                    <div class="col-md-4">
                        @if ($user->status)
                            <button class="btn btn-danger" type="submit">Suspend account</button>
                        @else
                            <button class="btn btn-success" type="submit">Reactivate account</button>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-danger mb-2" id="user-danger-zone">
            <div class="card-header"><h4 class="card-title text-danger">Danger zone</h4></div>
            <div class="card-body">
                @if ($account['closure'])
                    <p><strong>{{ $account['closure']['state_label'] }}</strong> — requested {{ $account['closure']['requested_at'] }}.</p>
                    @if ($account['closure']['state'] === 'closing')
                        <div class="alert alert-warning" role="status"><div class="alert-body">
                            Access is blocked, but cleanup is <strong>not finished</strong>. This account is not deleted.
                            @foreach ($account['closure']['pending_items'] as $item)<div>• {{ $item }}</div>@endforeach
                        </div></div>
                        <form method="POST" action="{{ route('admin.platform-users.closure.retry', $user->uid) }}">@csrf
                            <button class="btn btn-outline-danger" type="submit">Retry now</button></form>
                    @else
                        <p class="text-muted mb-0">Protected financial, provider and audit records are retained for accounting. Nothing further can be done to this account here.</p>
                    @endif
                @elseif ($account['verdict'] === 'blocked')
                    <p class="mb-0"><span class="badge badge-light-danger">Deletion blocked</span> {{ implode(' ', $account['reasons']) }}</p>
                @else
                    <p class="text-muted">Review exactly what will be deleted, closed, retained or blocked before confirming.</p>
                    <a class="btn btn-outline-danger" href="{{ route('admin.platform-users.delete', $user->uid) }}">
                        {{ $account['verdict'] === 'delete' ? 'Delete customer account…' : 'Close customer account…' }}</a>
                @endif
            </div>
        </div>

        <div class="card" id="user-history">
            <div class="card-header"><h4 class="card-title">Recent actions on this account</h4></div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>When</th><th>By</th><th>What</th><th>Reason</th></tr></thead>
                    <tbody>
                    @forelse ($history as $h)
                        <tr>
                            <td>{{ $h->created_at->toDayDateTimeString() }}</td>
                            <td>{{ $h->actor?->email }}</td>
                            <td>{{ $h->summary }}</td>
                            <td>{{ $h->reason }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted text-center py-2">No Platform Owner actions yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-body"><a href="{{ route('admin.platform-owner.audit') }}">Open the full audit log</a></div>
        </div>
    </section>
@endsection
