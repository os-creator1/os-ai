@extends('layouts/contentLayoutMaster')

@section('title', 'Administrators')

@section('content')
    <section id="admin-administrators-index">
        @include('admin.partials.flash')

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-start">
                <div>
                    <h4 class="card-title mb-25">Administrators</h4>
                    <p class="mb-0 text-muted">People who can sign in to the Platform Owner console, and what each role lets them do. New administrators are invited by email and choose their own password.</p>
                </div>
                @can('create administrator')
                    <a class="btn btn-primary" href="{{ route('admin.administrators.create') }}">Invite administrator</a>
                @endcan
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr><th>Administrator</th><th>Roles</th><th>Status</th><th>Last active</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($administrators as $admin)
                        <tr>
                            <td><strong>{{ trim($admin->first_name . ' ' . $admin->last_name) ?: '—' }}</strong><div class="small text-muted">{{ $admin->email }}</div></td>
                            <td>
                                @forelse ($admin->roles as $role)
                                    <span class="badge badge-light-primary">{{ $role->name }}</span>
                                @empty
                                    <span class="text-muted">No role</span>
                                @endforelse
                            </td>
                            <td>
                                @if ($admin->status)
                                    <span class="badge badge-light-success">Active</span>
                                @else
                                    <span class="badge badge-light-secondary">Inactive</span>
                                @endif
                            </td>
                            <td>{{ $admin->last_access_at ? $admin->last_access_at->diffForHumans() : 'Never signed in' }}</td>
                            <td class="text-end">
                                @can('edit administrator')
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.administrators.show', $admin->uid) }}">Edit</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No administrators.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-body">
                <a href="{{ route('admin.roles.index') }}">Manage roles</a> &middot;
                <a href="{{ route('admin.platform-owner.audit') }}">Audit Logs</a>
            </div>
        </div>
    </section>
@endsection
