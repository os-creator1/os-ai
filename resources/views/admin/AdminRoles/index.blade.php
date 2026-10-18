@extends('layouts/contentLayoutMaster')

@section('title', 'Roles')

@section('content')
    <section id="admin-roles-index">
        @include('admin.partials.flash')

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-start">
                <div>
                    <h4 class="card-title mb-25">Roles</h4>
                    <p class="mb-0 text-muted">A role is a named set of permissions you give to administrators. Changes apply the next time an administrator opens a page.</p>
                </div>
                @can('create roles')
                    <a class="btn btn-primary" href="{{ route('admin.roles.create') }}">New role</a>
                @endcan
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Role</th><th>Permissions</th><th>Administrators</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    @forelse ($roles as $role)
                        <tr>
                            <td><strong>{{ $role->name }}</strong></td>
                            <td>{{ count($role->permissions) }}</td>
                            <td>{{ $role->admins_count }}</td>
                            <td>
                                @if ($role->status)<span class="badge badge-light-success">Active</span>@else<span class="badge badge-light-secondary">Inactive</span>@endif
                            </td>
                            <td class="text-end">
                                @can('edit roles')
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.roles.show', $role->uid) }}">Edit permissions</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No roles.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-body"><a href="{{ route('admin.administrators.index') }}">Back to Administrators</a></div>
        </div>
    </section>
@endsection
