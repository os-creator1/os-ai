@extends('layouts/contentLayoutMaster')

@section('title', 'Support')

@section('content')
    <section id="admin-platform-support">
        @include('admin.partials.flash')

        <div class="card mb-2">
            <div class="card-header">
                <div>
                    <h4 class="card-title mb-25">Support</h4>
                    <p class="mb-0 text-muted">Find a customer, Workspace or Business, then check its state and take a support action.</p>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('admin.platform-support.index') }}" class="row g-1">
                    <div class="col-md-9">
                        <input class="form-control" type="search" name="q" value="{{ $term }}" placeholder="Name, email or ID" aria-label="Search name, email or ID" autofocus>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary" type="submit">Find</button></div>
                </form>
            </div>
        </div>

        @if ($results !== null)
            <div class="row">
                <div class="col-lg-4">
                    <div class="card" id="support-users">
                        <div class="card-header"><h5 class="card-title">Users</h5></div>
                        <ul class="list-group list-group-flush">
                            @forelse ($results['users'] as $u)
                                <li class="list-group-item">
                                    <a href="{{ route('admin.platform-users.show', $u->uid) }}">{{ $u->email }}</a>
                                    <div class="small text-muted">
                                        {{ trim($u->first_name . ' ' . $u->last_name) }}
                                        · {{ $u->status ? 'Active' : 'Suspended' }}
                                        · {{ $u->email_verified_at ? 'Verified' : 'Not verified' }}
                                    </div>
                                </li>
                            @empty
                                <li class="list-group-item text-muted">No users found.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card" id="support-workspaces">
                        <div class="card-header"><h5 class="card-title">Workspaces</h5></div>
                        <ul class="list-group list-group-flush">
                            @forelse ($results['workspaces'] as $w)
                                <li class="list-group-item">
                                    <a href="{{ route('admin.workspaces.show', $w) }}">{{ $w->name }}</a>
                                    <div class="small text-muted">Plan, access state and recovery on the Workspace page</div>
                                </li>
                            @empty
                                <li class="list-group-item text-muted">No Workspaces found.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card" id="support-businesses">
                        <div class="card-header"><h5 class="card-title">Businesses</h5></div>
                        <ul class="list-group list-group-flush">
                            @forelse ($results['businesses'] as $b)
                                <li class="list-group-item">
                                    <a href="{{ route('admin.businesses.show', $b) }}">{{ $b->name }}</a>
                                    <div class="small text-muted">{{ $b->email }}</div>
                                </li>
                            @empty
                                <li class="list-group-item text-muted">No Businesses found.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <div class="card mt-2">
            <div class="card-body">
                <h5>What you can do here</h5>
                <ul class="mb-0">
                    <li><strong>Why can't they use the product?</strong> Open the Workspace: it shows the access decision, plan, subscription, suspension and grace state, and the recovery control.</li>
                    <li><strong>Locked out or unverified?</strong> Open the user to send a password reset link or resend the verification email.</li>
                    <li><strong>What did we change?</strong> Every support action is recorded in the <a href="{{ route('admin.platform-owner.audit') }}">Audit Logs</a> with who did it and why.</li>
                    <li>Passwords are never visible and no one can be impersonated from here. "View As" belongs to Agency owners inside their own client Workspaces.</li>
                </ul>
            </div>
        </div>
    </section>
@endsection
