@extends('layouts/contentLayoutMaster')

@php
    // Customer Experience Slice 1 (Correction 3, contract §5): every
    // customer reaches this page through CustomerContext::accountNoun()/
    // accountsNoun() — "account"/"accounts" for Core/Growth and for the
    // unselected-multi-workspace chooser, "Agency account"/"Agency
    // accounts" only once a proven Agency frame is selected. The raw word
    // "Workspace" is never rendered here. The resolved context is the one
    // App\Http\Middleware\ResolveCustomerContext stores on the request, so
    // the controller's view data keeps its exact key shape.
    $resolvedCustomerContext = request()->attributes->get('customerContext');
    $accountNoun = $resolvedCustomerContext instanceof \App\Library\Navigation\CustomerContext ? $resolvedCustomerContext->accountNoun() : 'account';
    $accountNounPlural = $resolvedCustomerContext instanceof \App\Library\Navigation\CustomerContext ? $resolvedCustomerContext->accountsNoun() : 'accounts';
@endphp

@section('title', ucfirst($accountNoun) . ' overview')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection

@section('content')
    <section id="workspace-overview">
        <div class="row">
            <div class="col-12">
                <a href="{{ route('customer.workspaces.index') }}">Back to {{ $accountNounPlural }}</a>
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
                                <label class="form-label" for="workspace-rename">Rename {{ $accountNoun }}</label>
                                <input type="text" class="form-control" id="workspace-rename" name="name" value="{{ old('name', $workspace['name']) }}" required>
                            </div>

                            <x-button type="submit" variant="outline">Rename</x-button>
                        </form>
                    @endif

                    {{-- Account settings are the account name and the team. Deactivating
                         the account and transferring ownership are not customer
                         controls here: cancellation belongs to Plan & subscription,
                         and a real ownership handover needs its own designed flow
                         (identity by email, explicit confirmation, audit). The
                         backend actions stay in place for support. An account that
                         is already inactive can still be reactivated. --}}
                    @if ($workspace['role'] === 'Owner' && ! $workspace['is_active'])
                        <form method="POST" data-workspace-action="reactivate" class="mt-1">
                            @csrf

                            <button type="submit" class="btn btn-outline-success">Reactivate {{ $accountNoun }}</button>
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

                                    {{-- Customer names only (PlatformFeatureCopy); machine keys and
                                         features not built yet are never listed. --}}
                                    @php $planFeatureNames = \App\Library\Entitlement\PlatformFeatureCopy::names($entitlement['summary']->planFeatureKeys); @endphp
                                    <dt class="col-sm-4">Plan features</dt>
                                    <dd class="col-sm-8" data-role="plan-features">{{ $planFeatureNames === [] ? 'None' : implode(', ', $planFeatureNames) }}</dd>
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

                                @include('customer.business.partials.locale-fields', ['suggestDefaults' => true])

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
                            <x-empty-state icon="inbox" title="No Businesses are accessible in this {{ $accountNoun }}." />
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

                                    {{-- Customer Experience Slice 5, Correction Round 1 §8 — Client accounts → [Business] → Billing responsibility.
                                         Rendered only when the controller says the actor manages responsibility (Agency owner / Agency-wide Admin);
                                         the POST is authorized server-side by BillingProfileManager::assignPayer() regardless. Customer vocabulary only. --}}
                                    @if (isset($billingResponsibility))
                                        <div class="mt-2" id="client-billing-responsibility" data-role="billing-responsibility">
                                            <h5>{{ __('locale.usage_billing.responsibility.account_frame_title') }}</h5>
                                            <p class="text-caption mb-1">{{ __('locale.usage_billing.responsibility.account_frame_help') }}</p>
                                            @if (empty($billingResponsibility['businesses']))
                                                <p class="text-caption mb-0">{{ __('locale.usage_billing.responsibility.account_frame_empty') }}</p>
                                            @else
                                                @foreach ($billingResponsibility['businesses'] as $clientAccount)
                                                    <form method="POST" data-business-action="usage-billing/payer" data-business-uid="{{ $clientAccount['uid'] }}" data-role="billing-responsibility-form" class="mb-2" novalidate>
                                                        @csrf
                                                        <input type="hidden" name="return_to" value="account">
                                                        <fieldset>
                                                            <legend class="h6 mb-50">{{ $clientAccount['name'] }} &mdash; {{ __('locale.usage_billing.responsibility.account_frame_current') }} <span data-role="billing-responsibility-current">{{ $clientAccount['responsibility'] === 'agency' ? __('locale.usage_billing.responsibility.agency_pays_option') : __('locale.usage_billing.responsibility.client_pays_option') }}</span></legend>
                                                            <div class="form-check mb-50">
                                                                <input class="form-check-input" type="radio" name="billing_responsibility" id="billing-responsibility-agency-{{ $clientAccount['uid'] }}" value="agency" @checked($clientAccount['responsibility'] === 'agency')>
                                                                <label class="form-check-label" for="billing-responsibility-agency-{{ $clientAccount['uid'] }}"><strong>{{ __('locale.usage_billing.responsibility.agency_pays_option') }}</strong> &mdash; {{ __('locale.usage_billing.responsibility.agency_pays_option_help') }}</label>
                                                            </div>
                                                            <div class="form-check mb-50">
                                                                <input class="form-check-input" type="radio" name="billing_responsibility" id="billing-responsibility-client-{{ $clientAccount['uid'] }}" value="client" @checked($clientAccount['responsibility'] === 'client')>
                                                                <label class="form-check-label" for="billing-responsibility-client-{{ $clientAccount['uid'] }}"><strong>{{ __('locale.usage_billing.responsibility.client_pays_option') }}</strong> &mdash; {{ __('locale.usage_billing.responsibility.client_pays_option_help') }}</label>
                                                            </div>
                                                        </fieldset>
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('locale.usage_billing.responsibility.account_frame_button') }}</button>
                                                    </form>
                                                @endforeach
                                            @endif
                                        </div>
                                    @endif

                                    {{-- Business features: only what the server says this customer
                                         can switch (BusinessFeatureSettings) is ever sent here —
                                         nothing the plan leaves out, no locked rows. Each switch saves
                                         straight away through the existing enable/disable routes. --}}
                                    @php $featureSettings = $entitlement['featureSettings'] ?? []; @endphp
                                    @if (collect($featureSettings)->flatten(1)->isNotEmpty())
                                        <div id="business-feature-settings" class="mt-2">
                                            <h5>Features</h5>
                                            <p class="text-caption mb-1">Turn features on or off for each Business. Changes are saved straight away.</p>
                                            @foreach ($manageableBusinesses as $business)
                                                @if (! empty($featureSettings[$business['uid']] ?? []))
                                                    <h6 class="mt-1">{{ $business['name'] }}</h6>
                                                    <ul class="list-group mb-2" data-role="business-features">
                                                        @foreach ($featureSettings[$business['uid']] as $setting)
                                                            @php $switchId = 'business-feature-' . $business['uid'] . '-' . $loop->index; @endphp
                                                            <li class="list-group-item d-flex justify-content-between align-items-start" data-role="business-feature">
                                                                <div class="me-2">
                                                                    <div class="fw-bolder" id="{{ $switchId }}-name">{{ $setting['name'] }}</div>
                                                                    <div class="text-caption" id="{{ $switchId }}-description">{{ $setting['description'] }}</div>
                                                                    <div class="text-danger small mt-25" data-role="business-feature-error" role="alert" hidden></div>
                                                                </div>
                                                                <div class="form-check form-switch flex-shrink-0 mb-0">
                                                                    <input class="form-check-input" type="checkbox" role="switch" id="{{ $switchId }}" data-business-feature-switch data-business-uid="{{ $business['uid'] }}" data-feature="{{ $setting['key'] }}" aria-describedby="{{ $switchId }}-description" @checked($setting['enabled'])>
                                                                    {{-- The switch is named after the feature; its on/off state is
                                                                         the switch's own, so the visible word is not read twice. --}}
                                                                    <label class="form-check-label" for="{{ $switchId }}"><span class="visually-hidden">{{ $setting['name'] }}</span><span aria-hidden="true" data-role="business-feature-state">{{ $setting['enabled'] ? 'Enabled' : 'Disabled' }}</span></label>
                                                                </div>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
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

                            <x-input name="member_email" label="Email address" type="email" value="{{ old('member_email') }}" autocomplete="off" help="They need an existing Business OS account before you can add them." :error="$errors->first('member_email')" required />

                            <div class="mb-1">
                                <label class="form-label" for="member-role">Role</label>
                                <select class="form-control" id="member-role" name="role">
                                    <option value="staff">Staff</option>
                                    @if ($workspace['role'] === 'Owner')
                                        <option value="admin">Admin</option>
                                    @endif
                                </select>
                            </div>

                            @php
                                // Exactly one Business this person can grant: nothing to choose, so
                                // the new member gets access to that one Business only (selected
                                // scope — least privilege, never a hidden "all Businesses" grant).
                                $singleManageableBusiness = count($manageableBusinesses ?? []) === 1 ? $manageableBusinesses[0] : null;
                            @endphp

                            @if ($singleManageableBusiness !== null)
                                <input type="hidden" name="business_access_scope" value="selected">
                                <input type="hidden" name="business_uids[]" value="{{ $singleManageableBusiness['uid'] }}">
                                <p class="text-caption mb-1" data-role="member-single-business">They'll get access to {{ $singleManageableBusiness['name'] }}.</p>
                            @else
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
                            @endif

                            <x-button type="submit" variant="outline">Add member</x-button>
                        </form>

                        @if (empty($directory))
                            <x-empty-state icon="inbox" title="This {{ $accountNoun }} has no members." />
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

                    // Business feature switches: each change is saved at once through the
                    // existing enable/disable route (same CSRF, auth and entitlement checks),
                    // without leaving the page. The switch shows only what the server
                    // confirms; on any failure it returns to its previous state.
                    (function () {
                        var basePath = window.location.pathname.replace(/\/+$/, '');
                        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                        var genericError = 'We couldn\'t save that change. Please try again.';

                        document.querySelectorAll('input[data-business-feature-switch]').forEach(function (input) {
                            var row = input.closest('[data-role="business-feature"]');
                            var state = row.querySelector('[data-role="business-feature-state"]');
                            var error = row.querySelector('[data-role="business-feature-error"]');
                            var saving = false;

                            var show = function (enabled) {
                                input.checked = enabled;
                                state.textContent = enabled ? 'Enabled' : 'Disabled';
                            };

                            // A second click while a change is being saved does nothing.
                            input.addEventListener('click', function (event) {
                                if (saving) {
                                    event.preventDefault();
                                }
                            });

                            input.addEventListener('change', function () {
                                var wanted = input.checked;
                                var previous = ! wanted;
                                var url = [basePath, 'businesses', encodeURIComponent(input.getAttribute('data-business-uid')), 'features', encodeURIComponent(input.getAttribute('data-feature')), wanted ? 'enable' : 'disable'].join('/');

                                saving = true;
                                input.setAttribute('aria-disabled', 'true');
                                row.setAttribute('aria-busy', 'true');
                                error.hidden = true;
                                error.textContent = '';

                                fetch(url, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-Requested-With': 'XMLHttpRequest',
                                        'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : ''
                                    }
                                }).then(function (response) {
                                    return response.json().catch(function () {
                                        return null;
                                    }).then(function (body) {
                                        return { ok: response.ok, body: body };
                                    });
                                }).then(function (result) {
                                    if (result.ok && result.body && result.body.status === 'success' && typeof result.body.enabled === 'boolean') {
                                        show(result.body.enabled);

                                        return;
                                    }

                                    show(previous);
                                    error.textContent = (result.body && typeof result.body.customer_message === 'string') ? result.body.customer_message : genericError;
                                    error.hidden = false;
                                }).catch(function () {
                                    show(previous);
                                    error.textContent = genericError;
                                    error.hidden = false;
                                }).then(function () {
                                    saving = false;
                                    input.removeAttribute('aria-disabled');
                                    row.removeAttribute('aria-busy');
                                });
                            });
                        });
                    })();
                </script>
            @endif
        </div>
    </section>
@endsection
