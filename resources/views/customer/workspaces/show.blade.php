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
                    <x-alert variant="success">{{ session('flash_success') }}</x-alert>
                @endif

                @if (session('flash_error'))
                    <x-alert variant="danger">{{ session('flash_error') }}</x-alert>
                @endif

                @if ($errors->any())
                    <x-alert variant="danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif
            </div>

            <div class="col-12">
                <x-card title="{{ $workspace['name'] }}">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Status</dt>
                        <dd class="col-sm-9">
                            @if ($workspace['is_active'])
                                <x-badge variant="success">Active</x-badge>
                            @else
                                <x-badge variant="neutral">Inactive</x-badge>
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

                            <x-button type="submit" variant="outline">Rename</x-button>
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

                        <form method="POST" data-workspace-action="ownership/transfer" class="mt-1">
                            @csrf

                            <x-input name="new_owner_user_uid" label="New owner User UID" type="text" value="{{ old('new_owner_user_uid') }}" required />

                            <x-select
                                name="previous_owner_disposition"
                                label="Previous owner disposition"
                                :options="['deactivate' => 'Deactivate previous owner', 'convert_to_admin' => 'Convert previous owner to Admin']"
                                :selected="old('previous_owner_disposition', 'deactivate')"
                            />

                            <div class="mb-1" data-ownership-transfer-admin-fields>
                                <label class="form-label" for="ownership-transfer-scope">Business access</label>
                                <select class="form-control" id="ownership-transfer-scope" name="business_access_scope">
                                    <option value="all">All Businesses</option>
                                    <option value="selected">Selected Businesses</option>
                                </select>

                                @if (! empty($manageableBusinesses))
                                    <div class="mt-1">
                                        @foreach ($manageableBusinesses as $business)
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" name="business_uids[]" value="{{ $business['uid'] }}" id="ownership-transfer-business-{{ $business['uid'] }}">
                                                <label class="form-check-label" for="ownership-transfer-business-{{ $business['uid'] }}">{{ $business['name'] }}</label>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <button type="submit" class="btn btn-outline-warning">Transfer ownership</button>
                        </form>
                    @endif
                </x-card>
            </div>

            @isset($entitlement)
                <div class="col-12">
                    <div class="card" id="workspace-plan-capacity">
                        <div class="card-header">
                            <h4 class="card-title">Plan &amp; Capacity</h4>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-4">Assigned</dt>
                                <dd class="col-sm-8">{{ $entitlement['summary']->isAssigned ? 'Yes' : 'No' }}</dd>

                                @if ($entitlement['summary']->isAssigned)
                                    <dt class="col-sm-4">Tier</dt>
                                    <dd class="col-sm-8">{{ $entitlement['summary']->tierDisplayName }}</dd>

                                    <dt class="col-sm-4">Status</dt>
                                    <dd class="col-sm-8">{{ ucfirst($entitlement['summary']->status->value) }}</dd>

                                    <dt class="col-sm-4">Plan features</dt>
                                    <dd class="col-sm-8">
                                        @if (empty($entitlement['summary']->planFeatureKeys))
                                            None
                                        @else
                                            {{ implode(', ', $entitlement['summary']->planFeatureKeys) }}
                                        @endif
                                    </dd>
                                @endif

                                <dt class="col-sm-4">Current Businesses</dt>
                                <dd class="col-sm-8">{{ $entitlement['summary']->capacity->currentBusinessCount }}</dd>

                                <dt class="col-sm-4">Included slots</dt>
                                <dd class="col-sm-8">{{ $entitlement['summary']->capacity->includedSlots }}</dd>

                                <dt class="col-sm-4">Additional slots</dt>
                                <dd class="col-sm-8">{{ $entitlement['summary']->capacity->additionalSlotsAllocated }}</dd>

                                <dt class="col-sm-4">Effective capacity</dt>
                                <dd class="col-sm-8">
                                    @if ($entitlement['summary']->capacity->unlimited)
                                        Unlimited
                                    @elseif ($entitlement['summary']->capacity->effectiveCapacity !== null)
                                        {{ $entitlement['summary']->capacity->effectiveCapacity }}
                                    @else
                                        Unavailable ({{ $entitlement['summary']->capacity->denialReason }})
                                    @endif
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            @endisset

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Businesses</h4>
                    </div>
                    <div class="card-body">
                        @if (in_array($workspace['role'], ['Owner', 'Admin'], true))
                            <form method="POST" data-workspace-action="businesses" class="mb-2">
                                @csrf

                                <div class="mb-1">
                                    <label class="form-label" for="business-name">Business name</label>
                                    <input type="text" class="form-control" id="business-name" name="name" value="{{ old('name') }}" required>
                                </div>

                                <x-select
                                    name="industry"
                                    label="Industry"
                                    :options="collect(\App\Enums\Business\BusinessIndustry::cases())->mapWithKeys(fn ($industry) => [$industry->value => ucwords(str_replace('_', ' ', $industry->value))])->all()"
                                    :selected="old('industry')"
                                    required
                                />

                                <x-input name="industry_other" label="Industry (other)" type="text" value="{{ old('industry_other') }}" />

                                <div class="mb-1">
                                    <label class="form-label" for="business-description">Description</label>
                                    <textarea class="form-control" id="business-description" name="description" maxlength="5000">{{ old('description') }}</textarea>
                                </div>

                                <x-input name="email" label="Public email" type="email" value="{{ old('email') }}" />

                                <x-input name="phone" label="Phone" type="text" value="{{ old('phone') }}" />

                                <x-input name="website_url" label="Website" type="text" value="{{ old('website_url') }}" />

                                <div class="row">
                                    <div class="col-md-4">
                                        <x-input name="country_code" label="Country code" type="text" maxlength="2" value="{{ old('country_code') }}" required />
                                    </div>
                                    <div class="col-md-4">
                                        <x-input name="timezone" label="Timezone" type="text" value="{{ old('timezone') }}" required />
                                    </div>
                                    <div class="col-md-4">
                                        <x-input name="currency_code" label="Currency code" type="text" maxlength="3" value="{{ old('currency_code') }}" required />
                                    </div>
                                </div>

                                <x-button type="submit" variant="outline">Create Business</x-button>
                            </form>

                            @php
                                $reassignTargetWorkspaces = request()->attributes->get('reassignTargetWorkspaces', []);
                            @endphp

                            @if (! empty($manageableBusinesses) && ! empty($reassignTargetWorkspaces))
                                <x-table :headers="['Business', 'Reassign to']" class="mb-2">
                                    @foreach ($manageableBusinesses as $business)
                                        <tr>
                                            <td>{{ $business['name'] }}</td>
                                            <td>
                                                <form method="POST" data-business-action="reassign" data-business-uid="{{ $business['uid'] }}" class="d-flex">
                                                    @csrf
                                                    <select name="target_workspace_uid" class="form-control form-control-sm d-inline-block w-auto me-1">
                                                        @foreach ($reassignTargetWorkspaces as $targetWorkspace)
                                                            <option value="{{ $targetWorkspace['uid'] }}">{{ $targetWorkspace['name'] }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-button type="submit" variant="outline" size="sm">Reassign</x-button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </x-table>
                            @endif
                        @endif

                        @if (empty($businesses))
                            <x-empty-state icon="inbox" title="No Businesses are accessible in this Workspace." />
                        @else
                            <x-table :headers="['Name']">
                                @foreach ($businesses as $business)
                                    <tr>
                                        <td>{{ $business['name'] }}</td>
                                    </tr>
                                @endforeach
                            </x-table>
                        @endif

                        @isset($entitlement)
                            @if (! empty($manageableBusinesses))
                                <div class="mt-3">
                                    <h5>Usage &amp; Billing</h5>
                                    <ul class="mb-2">
                                        @foreach ($manageableBusinesses as $business)
                                            <li>
                                                {{ $business['name'] }} &mdash;
                                                <a href="#" data-business-action="usage-billing" data-business-uid="{{ $business['uid'] }}">Usage &amp; Billing</a>
                                            </li>
                                        @endforeach
                                    </ul>

                                    <h5>Platform feature preferences</h5>
                                    @foreach ($manageableBusinesses as $business)
                                        @php $businessFeatures = $entitlement['features'][$business['uid']] ?? []; @endphp
                                        @if (! empty($businessFeatures))
                                            <h6>{{ $business['name'] }}</h6>
                                            <div class="table-responsive mb-2">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Feature</th>
                                                            <th>Effective entitlement</th>
                                                            <th>Platform feature preference</th>
                                                            <th></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($businessFeatures as $featureKey => $row)
                                                            <tr>
                                                                <td>{{ $featureKey }}</td>
                                                                <td>
                                                                    @if ($row['decision']->allowed)
                                                                        <span class="badge badge-light-success">Allowed</span>
                                                                    @else
                                                                        <span class="badge badge-light-secondary">Denied ({{ $row['decision']->reason }})</span>
                                                                    @endif
                                                                </td>
                                                                <td>
                                                                    {{ $row['disablePreferenceRecorded'] ? 'Disable preference recorded' : 'No disable preference recorded' }}
                                                                    <div class="text-muted small">Runtime enforcement pending. This preference is stored at the Business level but the legacy module does not yet consult it.</div>
                                                                </td>
                                                                <td>
                                                                    @if ($row['disablePreferenceRecorded'])
                                                                        <form method="POST" data-business-action="features/{{ $featureKey }}/enable" data-business-uid="{{ $business['uid'] }}">
                                                                            @csrf
                                                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Remove disable preference</button>
                                                                        </form>
                                                                    @elseif ($row['decision']->allowed)
                                                                        <form method="POST" data-business-action="features/{{ $featureKey }}/disable" data-business-uid="{{ $business['uid'] }}">
                                                                            @csrf
                                                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Record disable preference</button>
                                                                        </form>
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        @endisset
                    </div>
                </div>
            </div>

            @isset($directory)
                <div class="col-12">
                    <x-card title="Members">
                        <form method="POST" data-workspace-action="members" class="mb-2">
                            @csrf

                            <x-input name="user_uid" label="User UID" type="text" value="{{ old('user_uid') }}" required />

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

                            <x-button type="submit" variant="outline">Add member</x-button>
                        </form>

                        @if (empty($directory))
                            <x-empty-state icon="inbox" title="This Workspace has no members." />
                        @else
                            @php
                                $manageableBusinessUids = collect($manageableBusinesses ?? [])->pluck('uid')->all();
                            @endphp
                            <x-table :headers="['Name', 'Role', 'Business access', 'Assigned Businesses', 'Status', 'Actions']">
                                @foreach ($directory as $member)
                                    <tr>
                                        <td>{{ $member['name'] }}</td>
                                        <td>{{ $member['role'] }}</td>
                                        <td>{{ $member['scope'] }}</td>
                                        <td>{{ $member['assigned_business_count'] }}</td>
                                        <td>
                                            @if ($member['is_active'])
                                                <x-badge variant="success">Active</x-badge>
                                            @else
                                                <x-badge variant="neutral">Inactive</x-badge>
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
                                                        <x-button type="submit" variant="outline" size="sm">Change role</x-button>
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

                                                        <x-button type="submit" variant="outline" size="sm">Update access</x-button>
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
                            </x-table>
                        @endif
                    </x-card>
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

                    document.querySelectorAll('form[data-business-action]').forEach(function (form) {
                        var basePath = window.location.pathname.replace(/\/+$/, '');
                        var businessUid = form.getAttribute('data-business-uid');
                        var resourcePath = ['businesses', businessUid, form.getAttribute('data-business-action')].join('/');
                        form.setAttribute('action', basePath + '/' + resourcePath);
                    });

                    document.querySelectorAll('a[data-business-action]').forEach(function (anchor) {
                        var basePath = window.location.pathname.replace(/\/+$/, '');
                        var businessUid = anchor.getAttribute('data-business-uid');
                        var resourcePath = ['businesses', businessUid, anchor.getAttribute('data-business-action')].join('/');
                        anchor.setAttribute('href', basePath + '/' + resourcePath);
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

                    document.querySelectorAll('select[name="previous_owner_disposition"]').forEach(function (select) {
                        var form = select.closest('form');
                        var adminFields = form ? form.querySelector('[data-ownership-transfer-admin-fields]') : null;

                        if (! adminFields) {
                            return;
                        }

                        var scopeSelect = adminFields.querySelector('select[name="business_access_scope"]');

                        var syncAdminFields = function () {
                            var isConvertToAdmin = select.value === 'convert_to_admin';
                            adminFields.style.display = isConvertToAdmin ? '' : 'none';

                            if (scopeSelect) {
                                scopeSelect.disabled = ! isConvertToAdmin;
                            }

                            adminFields.querySelectorAll('input[name="business_uids[]"]').forEach(function (checkbox) {
                                checkbox.disabled = ! isConvertToAdmin || (scopeSelect && scopeSelect.value === 'all');
                            });
                        };

                        select.addEventListener('change', syncAdminFields);

                        if (scopeSelect) {
                            scopeSelect.addEventListener('change', syncAdminFields);
                        }

                        syncAdminFields();
                    });
                </script>
            @endif
        </div>
    </section>
@endsection
