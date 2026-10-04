@extends('layouts/contentLayoutMaster')

@section('title', 'Users')

@section('content')
    <section id="admin-platform-users-index">
        @include('admin.partials.flash')

        <div class="card">
            <div class="card-header">
                <div>
                    <h4 class="card-title mb-25">Users</h4>
                    <p class="mb-0 text-muted">Customer accounts across every Workspace. Administrators are managed under Administrators.</p>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('admin.platform-users.index') }}" class="row g-1 mb-2">
                    <div class="col-md-6">
                        <input class="form-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search name or email" aria-label="Search name or email">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="state" aria-label="Filter by state">
                            <option value="">All accounts</option>
                            <option value="active" @selected(($filters['state'] ?? '') === 'active')>Active</option>
                            <option value="suspended" @selected(($filters['state'] ?? '') === 'suspended')>Suspended</option>
                            <option value="unverified" @selected(($filters['state'] ?? '') === 'unverified')>Email not verified</option>
                        </select>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary" type="submit">Search</button></div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>User</th>
                        <th>Status</th>
                        <th>Email</th>
                        <th>Workspaces</th>
                        <th>Last active</th>
                        <th class="text-end"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($users as $u)
                        <tr>
                            <td><strong>{{ trim($u->first_name . ' ' . $u->last_name) ?: '—' }}</strong><div class="text-muted small">{{ $u->email }}</div></td>
                            <td>
                                @if ($u->status)
                                    <span class="badge badge-light-success">Active</span>
                                @else
                                    <span class="badge badge-light-danger">Suspended</span>
                                @endif
                            </td>
                            <td>
                                @if ($u->email_verified_at)
                                    <span class="badge badge-light-success">Verified</span>
                                @else
                                    <span class="badge badge-light-warning">Not verified</span>
                                @endif
                            </td>
                            <td>
                                @forelse ($u->workspaces_summary as $w)
                                    <div><a href="{{ route('admin.workspaces.show', $w['uid']) }}">{{ $w['name'] }}</a> <span class="text-muted small">({{ $w['role'] }})</span></div>
                                @empty
                                    <span class="text-muted">None</span>
                                @endforelse
                            </td>
                            <td>{{ $u->last_access_at ? $u->last_access_at->diffForHumans() : 'Never' }}</td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.platform-users.show', $u->uid) }}">Manage</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No users match.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-body">{{ $users->links() }}</div>
        </div>
    </section>
@endsection
