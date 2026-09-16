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

    // One page, two Settings destinations: `account` is Settings → Account
    // details (the account itself and, for an Agency, its client accounts);
    // `team` is Settings → Team (members, roles, Business access). Nothing
    // renders under both. A Core or Growth account never reaches the overview
    // (WorkspaceController::show() sends its customer to their Business's
    // Settings); before its first Business exists it gets `first-business`,
    // which is only the form that creates it.
    $section = $section ?? 'account';
@endphp

@section('title', $section === 'team' ? 'Team' : ucfirst($accountNoun) . ' overview')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection

@section('content')
    <section id="workspace-overview">
        <div class="row">
            @if (request()->attributes->get('showsAccountChooser', false))
                <div class="col-12">
                    <a href="{{ route('customer.workspaces.index') }}">Back to {{ $accountNounPlural }}</a>
                </div>
            @endif

            <div class="col-12">
                {{-- A successful change is confirmed by the product's compact toast
                     (toastr, loaded on every page: auto-dismissing, announced
                     politely to assistive technology). The alert below is only
                     the fallback when toastr is unavailable; errors stay as
                     full, persistent alerts. --}}
                @if (session('flash_success'))
                    <div data-role="flash-success-fallback" hidden>
                        <x-alert variant="success">{{ session('flash_success') }}</x-alert>
                    </div>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            if (window.toastr) {
                                window.toastr.success(@json(session('flash_success')), '', {
                                    closeButton: true,
                                    positionClass: 'toast-top-right',
                                    progressBar: true,
                                    newestOnTop: true,
                                    rtl: typeof isRtl !== 'undefined' && isRtl
                                });

                                return;
                            }

                            document.querySelector('[data-role="flash-success-fallback"]').hidden = false;
                        });
                    </script>
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

            @if ($section === 'account')
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

                        {{-- The plan itself — what it includes, capacity, billing — is
                             its own page (Settings → Plan & subscription); the account
                             page only names it. --}}
                        @isset($entitlement)
                            <dt class="col-sm-3">Plan</dt>
                            <dd class="col-sm-9" data-role="account-plan">
                                {{ $entitlement['summary']->isAssigned ? $entitlement['summary']->tierDisplayName : 'Not set up yet' }}
                                &middot; <a href="{{ route('customer.workspaces.plan.show', request()->route('workspaceUid')) }}">Plan &amp; subscription</a>
                            </dd>
                        @endisset
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

                    {{-- Account details are the account name (the team is Settings → Team). Deactivating
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
            @endif

            @if (in_array($section, ['account', 'first-business'], true))
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ $section === 'first-business' ? 'Your Business' : 'Businesses' }}</h4>
                    </div>
                    <div class="card-body">
                        {{-- Creating a Business: an Agency's client accounts, or a Core or
                             Growth account's FIRST Business. A Core or Growth account that
                             already has its Business never reaches this page. --}}
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

                            {{-- Moving a Business into another Workspace is Workspace
                                 mechanics, not an account setting: no customer control
                                 here (the reassignment route stays for support). --}}
                        @endif

                        @if ($section === 'account')
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
                                                    @if ($clientAccount['responsibility'] === 'managing_agency')
                                                        {{-- Implementation Contract 09 — AgencyRebill is shown truthfully and read-only: the agency/client selector below cannot express it and must never offer to change it. --}}
                                                        <p class="mb-2" data-role="billing-responsibility-managing-agency" data-business-uid="{{ $clientAccount['uid'] }}"><strong>{{ $clientAccount['name'] }}</strong> &mdash; {{ __('locale.usage_billing.responsibility.account_frame_current') }} <span data-role="billing-responsibility-current">{{ __('locale.usage_billing.responsibility.managing_agency_option') }}</span></p>
                                                        @continue
                                                    @endif
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
                                        @include('customer.workspaces.partials.business-feature-switches', ['workspaceUid' => request()->route('workspaceUid'), 'businesses' => $manageableBusinesses, 'featureSettings' => $featureSettings, 'showHeading' => true, 'showBusinessNames' => true])
                                    @endif
                                </div>
                            @endif
                        @endisset
                    </div>
                </div>
            </div>
            @endif

            @if ($section === 'team' && isset($directory))
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
            @endif

            @if (in_array($workspace['role'], ['Owner', 'Admin'], true))
                <script>
                    // The account page and Settings → Team share these forms, so their
                    // actions are built from the account URL, never from the page URL.
                    var accountBasePath = @json(route('customer.workspaces.show', request()->route('workspaceUid')));

                    document.querySelectorAll('form[data-workspace-action]').forEach(function (form) {
                        var basePath = accountBasePath;
                        form.setAttribute('action', basePath + '/' + form.getAttribute('data-workspace-action'));
                    });

                    document.querySelectorAll('form[data-member-action]').forEach(function (form) {
                        var basePath = accountBasePath;
                        var memberUid = form.getAttribute('data-member-uid');
                        form.setAttribute('action', basePath + '/members/' + memberUid + '/' + form.getAttribute('data-member-action'));
                    });

                    document.querySelectorAll('form[data-business-action]').forEach(function (form) {
                        var basePath = accountBasePath;
                        var businessUid = form.getAttribute('data-business-uid');
                        var resourcePath = ['businesses', businessUid, form.getAttribute('data-business-action')].join('/');
                        form.setAttribute('action', basePath + '/' + resourcePath);
                    });

                    document.querySelectorAll('a[data-business-action]').forEach(function (anchor) {
                        var basePath = accountBasePath;
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

                </script>
            @endif
        </div>
    </section>
@endsection
