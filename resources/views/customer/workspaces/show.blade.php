@extends('layouts/contentLayoutMaster')

@section('title', 'Workspace overview')

@section('content')
    <section id="workspace-overview">
        <div class="row">
            <div class="col-12">
                <a href="{{ route('customer.workspaces.index') }}">Back to Workspaces</a>
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
                        <h4 class="card-title">{{ $workspace['name'] }}</h4>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9">
                                @if ($workspace['is_active'])
                                    <span class="badge badge-light-success">Active</span>
                                @else
                                    <span class="badge badge-light-secondary">Inactive</span>
                                @endif
                            </dd>

                            <dt class="col-sm-3">Your role</dt>
                            <dd class="col-sm-9">{{ $workspace['role'] }}</dd>
                        </dl>

                        @if (in_array($workspace['role'], ['Owner', 'Admin'], true))
                            <form method="POST" data-workspace-action="rename" class="mt-1">
                                @csrf

                                <div class="mb-1">
                                    <label class="form-label" for="workspace-rename">Rename Workspace</label>
                                    <input type="text" class="form-control" id="workspace-rename" name="name" value="{{ old('name', $workspace['name']) }}" required>
                                </div>

                                <button type="submit" class="btn btn-outline-primary">Rename</button>
                            </form>
                        @endif

                        @if ($workspace['role'] === 'Owner')
                            @if ($workspace['is_active'])
                                <form method="POST" data-workspace-action="deactivate" class="mt-1">
                                    @csrf

                                    <button type="submit" class="btn btn-outline-danger">Deactivate Workspace</button>
                                </form>
                            @else
                                <form method="POST" data-workspace-action="reactivate" class="mt-1">
                                    @csrf

                                    <button type="submit" class="btn btn-outline-success">Reactivate Workspace</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Businesses</h4>
                    </div>
                    <div class="card-body">
                        @if (empty($businesses))
                            <p class="mb-0">No Businesses are accessible in this Workspace.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($businesses as $business)
                                            <tr>
                                                <td>{{ $business['name'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            @isset($directory)
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title">Members</h4>
                        </div>
                        <div class="card-body">
                            <form method="POST" data-workspace-action="members" class="mb-2">
                                @csrf

                                <div class="mb-1">
                                    <label class="form-label" for="member-user-uid">User UID</label>
                                    <input type="text" class="form-control" id="member-user-uid" name="user_uid" value="{{ old('user_uid') }}" required>
                                </div>

                                <div class="mb-1">
                                    <label class="form-label" for="member-role">Role</label>
                                    <select class="form-control" id="member-role" name="role">
                                        <option value="staff">Staff</option>
                                        @if ($workspace['role'] === 'Owner')
                                            <option value="admin">Admin</option>
                                        @endif
                                    </select>
                                </div>

                                <div class="mb-1">
                                    <label class="form-label" for="member-scope">Business access</label>
                                    <select class="form-control" id="member-scope" name="business_access_scope">
                                        <option value="all">All Businesses</option>
                                        <option value="selected">Selected Businesses</option>
                                    </select>
                                </div>

                                @if (! empty($manageableBusinesses))
                                    <div class="mb-1">
                                        @foreach ($manageableBusinesses as $business)
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" name="business_uids[]" value="{{ $business['uid'] }}" id="add-member-business-{{ $business['uid'] }}">
                                                <label class="form-check-label" for="add-member-business-{{ $business['uid'] }}">{{ $business['name'] }}</label>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <button type="submit" class="btn btn-outline-primary">Add member</button>
                            </form>

                            @if (empty($directory))
                                <p class="mb-0">This Workspace has no members.</p>
                            @else
                                @php
                                    $manageableBusinessUids = collect($manageableBusinesses ?? [])->pluck('uid')->all();
                                @endphp
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Role</th>
                                                <th>Business access</th>
                                                <th>Assigned Businesses</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($directory as $member)
                                                <tr>
                                                    <td>{{ $member['name'] }}</td>
                                                    <td>{{ $member['role'] }}</td>
                                                    <td>{{ $member['scope'] }}</td>
                                                    <td>{{ $member['assigned_business_count'] }}</td>
                                                    <td>
                                                        @if ($member['is_active'])
                                                            <span class="badge badge-light-success">Active</span>
                                                        @else
                                                            <span class="badge badge-light-secondary">Inactive</span>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @php
                                                            $viewerIsOwner = $workspace['role'] === 'Owner';
                                                            $viewerCanManageLifecycle = $viewerIsOwner || $member['role'] !== 'Admin';
                                                            $viewerCanSeeMembersCompleteAccess = empty(array_diff($member['assigned_business_uids'], $manageableBusinessUids));
                                                        @endphp
                                                        @if ($member['is_active'])
                                                            @if ($viewerIsOwner)
                                                                <form method="POST" data-member-action="role" data-member-uid="{{ $member['uid'] }}" class="mb-1">
                                                                    @csrf
                                                                    <select name="role" class="form-control form-control-sm d-inline-block w-auto">
                                                                        <option value="staff" @selected($member['role'] === 'Staff')>Staff</option>
                                                                        <option value="admin" @selected($member['role'] === 'Admin')>Admin</option>
                                                                    </select>
                                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Change role</button>
                                                                </form>
                                                            @endif

                                                            @if ($viewerCanSeeMembersCompleteAccess)
                                                                <form method="POST" data-member-action="access" data-member-uid="{{ $member['uid'] }}" class="mb-1">
                                                                    @csrf
                                                                    <select name="business_access_scope" class="form-control form-control-sm d-inline-block w-auto">
                                                                        <option value="all" @selected($member['scope'] === 'All Businesses')>All Businesses</option>
                                                                        <option value="selected" @selected($member['scope'] === 'Selected Businesses')>Selected Businesses</option>
                                                                    </select>

                                                                    @if (! empty($manageableBusinesses))
                                                                        @foreach ($manageableBusinesses as $business)
                                                                            <div class="form-check form-check-inline">
                                                                                <input class="form-check-input" type="checkbox" name="business_uids[]" value="{{ $business['uid'] }}" id="access-{{ $member['uid'] }}-{{ $business['uid'] }}" @checked(in_array($business['uid'], $member['assigned_business_uids'], true))>
                                                                                <label class="form-check-label" for="access-{{ $member['uid'] }}-{{ $business['uid'] }}">{{ $business['name'] }}</label>
                                                                            </div>
                                                                        @endforeach
                                                                    @endif

                                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Update access</button>
                                                                </form>
                                                            @else
                                                                <p class="mb-1 text-muted">Business access can only be changed by a manager who can see this member's complete assigned Businesses.</p>
                                                            @endif

                                                            @if ($viewerCanManageLifecycle)
                                                                <form method="POST" data-member-action="deactivate" data-member-uid="{{ $member['uid'] }}">
                                                                    @csrf
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                                                </form>
                                                            @endif
                                                        @else
                                                            @if ($viewerCanManageLifecycle)
                                                                <form method="POST" data-member-action="reactivate" data-member-uid="{{ $member['uid'] }}">
                                                                    @csrf
                                                                    <button type="submit" class="btn btn-sm btn-outline-success">Reactivate</button>
                                                                </form>
                                                            @endif
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endisset

            @if (in_array($workspace['role'], ['Owner', 'Admin'], true))
                <script>
                    document.querySelectorAll('form[data-workspace-action]').forEach(function (form) {
                        var basePath = window.location.pathname.replace(/\/+$/, '');
                        form.setAttribute('action', basePath + '/' + form.getAttribute('data-workspace-action'));
                    });

                    document.querySelectorAll('form[data-member-action]').forEach(function (form) {
                        var basePath = window.location.pathname.replace(/\/+$/, '');
                        var memberUid = form.getAttribute('data-member-uid');
                        form.setAttribute('action', basePath + '/members/' + memberUid + '/' + form.getAttribute('data-member-action'));
                    });

                    document.querySelectorAll('select[name="business_access_scope"]').forEach(function (select) {
                        var form = select.closest('form');

                        if (! form) {
                            return;
                        }

                        var syncBusinessCheckboxes = function () {
                            var isAllScope = select.value === 'all';

                            form.querySelectorAll('input[name="business_uids[]"]').forEach(function (checkbox) {
                                checkbox.disabled = isAllScope;

                                if (isAllScope) {
                                    checkbox.checked = false;
                                }
                            });
                        };

                        select.addEventListener('change', syncBusinessCheckboxes);
                        syncBusinessCheckboxes();
                    });
                </script>
            @endif
        </div>
    </section>
@endsection
