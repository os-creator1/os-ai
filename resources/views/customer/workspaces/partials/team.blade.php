{{--
    Settings → Team: invite a member, then manage the people who already belong to
    this account. Presentation only — every form below posts to the same
    WorkspaceController member endpoints as before (the page script in
    show.blade.php rewrites each form's action from data-workspace-action /
    data-member-action), and every control is shown only when the existing rules
    allow it (Owner-only role changes, Admin members only manageable by the
    Owner, access edits only when the manager can see the member's complete
    Business set). A person with no MotionGrove account yet gets a pending
    invitation, listed under Pending invitations until they accept; they then
    appear under Members.
--}}
@php
    $manageableBusinessUids = collect($manageableBusinesses ?? [])->pluck('uid')->all();
    $singleManageableBusiness = count($manageableBusinesses ?? []) === 1 ? $manageableBusinesses[0] : null;
    $teamHeadingContext = $singleManageableBusiness['name'] ?? $workspace['name'];
    $viewerIsOwner = $workspace['role'] === 'Owner';
    $initialsOf = function (string $name): string {
        $letters = '';
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
            if ($part !== '' && strlen($letters) < 2) {
                $letters .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }
        return $letters !== '' ? $letters : '?';
    };
    $peopleCount = count($directory) + (! empty($teamOwner) ? 1 : 0);
@endphp


<style>
    .sr-member { display: block; }
    .sr-member__details { width: 100%; }
    .sr-member__summary { display: flex; align-items: center; gap: .6rem; cursor: pointer; list-style: none; flex-wrap: wrap; }
    .sr-member__summary::-webkit-details-marker { display: none; }
    .sr-member__summary--static { cursor: default; }
    .sr-member__name { flex: 1 1 8rem; min-width: 0; }
    .sr-member__manage { display: inline-flex; align-items: center; gap: .25rem; margin-left: auto; font-size: .85rem; color: var(--bs-secondary-color, #6b7280); }
    .sr-member__details[open] .sr-member__manage svg { transform: rotate(180deg); }
    .sr-member__panel { margin-top: .75rem; padding: .85rem 1rem; border: 1px solid var(--bs-border-color, #e5e7eb); border-radius: .5rem; display: grid; gap: .9rem; }
    .sr-member__inline { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .sr-member__inline select { width: auto; min-width: 8rem; }
    .sr-member__access { display: grid; gap: .5rem; }
    .sr-seg { display: inline-flex; border: 1px solid var(--bs-border-color, #d1d5db); border-radius: .4rem; overflow: hidden; width: fit-content; max-width: 100%; }
    .sr-seg label { margin: 0; cursor: pointer; }
    .sr-seg input { position: absolute; opacity: 0; pointer-events: none; }
    .sr-seg span { display: block; padding: .3rem .7rem; font-size: .85rem; }
    .sr-seg input:checked + span { background: var(--bs-primary, #4f46e5); color: #fff; }
    .sr-bizpick { display: flex; flex-wrap: wrap; gap: .4rem; }
    .sr-bizpick__item { margin: 0; cursor: pointer; }
    .sr-bizpick__item input { position: absolute; opacity: 0; pointer-events: none; }
    .sr-bizpick__item span { display: inline-block; padding: .2rem .65rem; border: 1px solid var(--bs-border-color, #d1d5db); border-radius: 999px; font-size: .85rem; }
    .sr-bizpick__item input:checked + span { border-color: var(--bs-primary, #4f46e5); background: rgba(79, 70, 229, .1); font-weight: 600; }
    .sr-bizpick__item input:checked + span::before { content: "\2713\00a0"; }
    .sr-bizpick__item input:focus-visible + span, .sr-seg input:focus-visible + span { outline: 2px solid var(--bs-primary, #4f46e5); outline-offset: 1px; }
    .sr-member__access:has(input[value="all"]:checked) .sr-bizpick { display: none; }
    .sr-member__delete { display: flex; justify-content: flex-end; }
    @media (max-width: 575.98px) { .sr-member__manage { margin-left: 0; } .sr-member__inline select { flex: 1 1 8rem; } }
</style>

<div class="col-12">
    <div class="sr-page sr-page--narrow">
        <a href="{{ route('customer.workspaces.settings.show', request()->route('workspaceUid')) }}" class="sr-back">
            <x-ds-icon name="arrow-left" size="14" aria-hidden="true" /> Back to Settings
        </a>
        <div class="sr-head">
            <div class="sr-head__main">
                <h1 class="sr-title">Team</h1>
                <p class="sr-lede">Manage members and their access to {{ $teamHeadingContext }}.</p>
            </div>
        </div>

        <div class="sr-card" data-section="team-add">
            <div class="sr-card__body">
                <form method="POST" data-workspace-action="members">
                    @csrf
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <span class="sr-tile"><x-ds-icon name="user-plus" size="18" /></span>
                        <div>
                            <h2 class="sr-card__title">Invite a member</h2>
                            @if ($singleManageableBusiness !== null)
                                <p class="sr-card__sub" data-role="member-single-business">They'll get access to {{ $singleManageableBusiness['name'] }} with the role you choose. We'll email an invitation if they don't have a MotionGrove account yet.</p>
                            @else
                                <p class="sr-card__sub">They'll get access with the role you choose. We'll email an invitation if they don't have a MotionGrove account yet.</p>
                            @endif
                        </div>
                    </div>

                    <div class="d-flex gap-3 align-items-start flex-wrap">
                        <div class="sr-field flex-grow-1" style="min-width:220px">
                            <label for="member_email">Email address</label>
                            <input type="email" id="member_email" name="member_email" value="{{ old('member_email') }}" autocomplete="off"
                                   placeholder="name@company.com" required
                                   class="form-control @error('member_email') is-invalid @enderror">
                            @if ($errors->first('member_email'))
                                <p class="sr-error">{{ $errors->first('member_email') }}</p>
                            @else
                                <p class="sr-hint">Anyone can be invited: if they're new to MotionGrove they'll set up their sign-in from the email.</p>
                            @endif
                        </div>
                        <div class="sr-field" style="min-width:160px">
                            <label for="member-role">Role</label>
                            <select class="form-select" id="member-role" name="role">
                                <option value="staff">Staff</option>
                                @if ($viewerIsOwner)
                                    <option value="admin">Admin</option>
                                @endif
                            </select>
                        </div>
                        <div class="sr-field" style="padding-top:22px">
                            <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-1">
                                <x-ds-icon name="plus" size="16" /> Invite member
                            </button>
                        </div>
                    </div>

                    @if ($singleManageableBusiness !== null)
                        {{-- Exactly one Business this person can grant: nothing to choose, so the new
                             member gets access to that one Business only (selected scope — least
                             privilege, never a hidden "all Businesses" grant). --}}
                        <input type="hidden" name="business_access_scope" value="selected">
                        <input type="hidden" name="business_uids[]" value="{{ $singleManageableBusiness['uid'] }}">
                    @else
                        <div class="sr-field mt-3" style="max-width:260px">
                            <label for="member-scope">Business access</label>
                            <select class="form-select" id="member-scope" name="business_access_scope">
                                <option value="all">All Businesses</option>
                                <option value="selected">Selected Businesses</option>
                            </select>
                        </div>
                    @endif
                </form>
            </div>
        </div>

        @if (! empty($invitations))
            <div class="sr-card" data-section="team-invitations">
                <div class="sr-card__head">
                    <h2 class="sr-card__title">Pending invitations</h2>
                    <span class="sr-card__meta">{{ count($invitations) }}</span>
                </div>
                <ul class="sr-list">
                    @foreach ($invitations as $invitation)
                        @php
                            $viewerCanManageInvitation = $viewerIsOwner || $invitation['role'] !== 'Admin';
                        @endphp
                        <li class="sr-list__row" style="align-items:flex-start" data-invitation-uid="{{ $invitation['uid'] }}">
                            <span class="sr-avatar">{{ $initialsOf($invitation['email']) }}</span>
                            <div class="sr-list__main">
                                <div class="sr-list__name">
                                    {{ $invitation['email'] }}
                                    <span class="sr-chip">{{ $invitation['role'] }}</span>
                                    <span class="sr-chip {{ $invitation['status'] === 'Pending' ? 'sr-chip--success' : '' }}">{{ $invitation['status'] }}</span>
                                </div>
                                <div class="sr-list__sub">{{ $invitation['status'] === 'Expired' ? 'Expired' : 'Expires' }} {{ $invitation['expires'] }}</div>
                                @if ($viewerCanManageInvitation)
                                    <div class="d-flex gap-2 mt-2">
                                        <form method="POST" data-invitation-action="resend" data-invitation-uid="{{ $invitation['uid'] }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Resend</button>
                                        </form>
                                        <form method="POST" data-invitation-action="revoke" data-invitation-uid="{{ $invitation['uid'] }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-sr-danger-outline">Revoke</button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="sr-card" data-section="team-members">
            <div class="sr-card__head">
                <h2 class="sr-card__title">Members</h2>
                <span class="sr-card__meta">{{ $peopleCount }} {{ $peopleCount === 1 ? 'person' : 'people' }}</span>
            </div>

            <ul class="sr-list">
                @if (! empty($teamOwner))
                    <li class="sr-list__row" data-role="team-owner">
                        <span class="sr-avatar">{{ $initialsOf($teamOwner['name']) }}</span>
                        <div class="sr-list__main">
                            <div class="sr-list__name">{{ $teamOwner['name'] }}@if ($teamOwner['is_you']) <span class="fw-normal text-muted">(you)</span>@endif</div>
                            @if ($teamOwner['email'] !== '')
                                <div class="sr-list__sub">{{ $teamOwner['email'] }}</div>
                            @endif
                        </div>
                        <span class="sr-chip">Owner role</span>
                    </li>
                @endif

                @foreach ($directory as $member)
                    @php
                        $viewerCanManageLifecycle = $viewerIsOwner || $member['role'] !== 'Admin';
                        $viewerCanSeeMembersCompleteAccess = empty(array_diff($member['assigned_business_uids'], $manageableBusinessUids));
                        $canManage = $viewerCanManageLifecycle || $viewerIsOwner;
                        $deleteConfirm = 'Delete this member from ' . $teamHeadingContext . '? They will immediately lose access. Their personal MotionGrove account will not be deleted, and their access to other Workspaces is unaffected.';
                    @endphp
                    <li class="sr-list__row sr-member" data-member-uid="{{ $member['uid'] }}">
                        <details class="sr-member__details">
                            <summary class="sr-member__summary @unless ($canManage) sr-member__summary--static @endunless">
                                <span class="sr-avatar">{{ $initialsOf($member['name']) }}</span>
                                <span class="sr-list__name sr-member__name">{{ $member['name'] }}</span>
                                <span class="sr-chip">{{ $member['role'] }}</span>
                                @if ($member['is_active'])
                                    <span class="sr-chip sr-chip--success">Active</span>
                                @else
                                    <span class="sr-chip">Inactive</span>
                                @endif
                                @if ($canManage)
                                    <span class="sr-member__manage">Manage <x-ds-icon name="chevron-down" size="14" aria-hidden="true" /></span>
                                @endif
                            </summary>

                            @if ($canManage)
                                <div class="sr-member__panel">
                                    @if ($member['is_active'])
                                        @if ($viewerIsOwner)
                                            <form method="POST" data-member-action="role" data-member-uid="{{ $member['uid'] }}" class="sr-member__section sr-member__inline">
                                                @csrf
                                                <label class="sr-label" for="role-{{ $member['uid'] }}">Role</label>
                                                <select name="role" id="role-{{ $member['uid'] }}" class="form-select form-select-sm">
                                                    <option value="staff" @selected($member['role'] === 'Staff')>Staff</option>
                                                    <option value="admin" @selected($member['role'] === 'Admin')>Admin</option>
                                                </select>
                                                <x-button type="submit" variant="secondary" size="sm">Save</x-button>
                                            </form>
                                        @endif

                                        @if ($viewerCanSeeMembersCompleteAccess)
                                            <form method="POST" data-member-action="access" data-member-uid="{{ $member['uid'] }}" class="sr-member__section sr-member__access">
                                                @csrf
                                                <span class="sr-label">Business access</span>
                                                <div class="sr-seg">
                                                    <label><input type="radio" name="business_access_scope" value="all" @checked($member['scope'] === 'All Businesses')><span>All Businesses</span></label>
                                                    <label><input type="radio" name="business_access_scope" value="selected" @checked($member['scope'] === 'Selected Businesses')><span>Selected Businesses</span></label>
                                                </div>
                                                <div class="sr-bizpick" data-role="business-picker">
                                                    @foreach ($manageableBusinesses ?? [] as $business)
                                                        <label class="sr-bizpick__item">
                                                            <input type="checkbox" id="access-{{ $member['uid'] }}-{{ $business['uid'] }}" name="business_uids[]" value="{{ $business['uid'] }}" @checked(in_array($business['uid'], $member['assigned_business_uids'], true))>
                                                            <span>{{ $business['name'] }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                                <div><x-button type="submit" variant="secondary" size="sm">Save changes</x-button></div>
                                            </form>
                                        @else
                                            <p class="sr-hint mb-0">Business access can only be changed by a manager who can see this member's complete assigned Businesses.</p>
                                        @endif
                                    @elseif ($viewerCanManageLifecycle)
                                        <form method="POST" data-member-action="reactivate" data-member-uid="{{ $member['uid'] }}" class="sr-member__section">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Reactivate</button>
                                        </form>
                                    @endif

                                    @if ($viewerCanManageLifecycle)
                                        <form method="POST" data-member-action="remove" data-member-uid="{{ $member['uid'] }}" class="sr-member__delete"
                                              data-confirm="{{ $deleteConfirm }}" onsubmit="return confirm(this.dataset.confirm)">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-sr-danger-outline d-inline-flex align-items-center gap-1">
                                                <x-ds-icon name="trash" size="14" aria-hidden="true" /> Delete member
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                        </details>
                    </li>
                @endforeach
            </ul>

            @if (empty($directory))
                <div class="sr-empty" data-role="team-empty">
                    <span class="sr-tile"><x-ds-icon name="users" size="18" /></span>
                    <strong>No one else yet</strong>
                    <p>People you invite appear here once they join, with their role and what they can open.</p>
                </div>
            @endif
        </div>
    </div>
</div>
