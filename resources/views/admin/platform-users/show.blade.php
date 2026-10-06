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
                        @if ($user->status)<span class="badge badge-light-success">Active</span>@else<span class="badge badge-light-danger">Suspended</span>@endif
                    </dd>
                    <dt class="col-sm-3">Email verification</dt>
                    <dd class="col-sm-9">{{ $user->email_verified_at ? 'Verified ' . $user->email_verified_at->toFormattedDateString() : 'Not verified' }}</dd>
                    <dt class="col-sm-3">Joined</dt><dd class="col-sm-9">{{ $user->created_at?->toFormattedDateString() }}</dd>
                    <dt class="col-sm-3">Last active</dt><dd class="col-sm-9">{{ $user->last_access_at ? $user->last_access_at->diffForHumans() : 'Never' }}</dd>
                    <dt class="col-sm-3">Workspaces</dt>
                    <dd class="col-sm-9">
                        @forelse ($user->workspaces_summary as $w)
                            <div><a href="{{ route('admin.workspaces.show', $w['uid']) }}">{{ $w['name'] }}</a> <span class="text-muted small">({{ $w['role'] }})</span></div>
                        @empty
                            <span class="text-muted">None</span>
                        @endforelse
                    </dd>
                </dl>
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
