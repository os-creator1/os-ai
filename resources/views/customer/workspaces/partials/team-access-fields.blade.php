{{--
    Access & permissions — shared by "Invite a member" and a pending invitation's "Edit access", so both
    collect exactly the same things and post the same field names:

      role                     Staff, or Admin only when the viewer may grant it
      business_access_scope    all | selected   (+ business_uids[] picker; a single grantable Business is
                               fixed to that Business, as before)
      visible_modules[]        the switches left ON (WorkspaceMemberModule); defaults to all ON
      modules_submitted=1      marks that the module section was part of the form

    Presentation only: WorkspaceController / WorkspaceMemberInvitationManager decide what is allowed.
    Expects: $idp (unique id prefix), $viewerIsOwner, $manageableBusinesses, $singleManageableBusiness,
    $selectedRole ('staff'|'admin'), $selectedScope ('all'|'selected'), $selectedBusinessUids (array),
    $selectedModules (array of module values), $roleSelectId (optional id for the role select).
--}}
@php
    $roleSelectId = $roleSelectId ?? ($idp . '-role');
    $scopeSelectId = $scopeSelectId ?? ($idp . '-scope');
    $modules = \App\Enums\Workspace\WorkspaceMemberModule::cases();
@endphp
<fieldset class="sr-access" data-access-form data-role="access-permissions">
    <legend class="sr-access__title">Access &amp; permissions</legend>

    <div class="sr-access__row">
        <div class="sr-field">
            <label for="{{ $roleSelectId }}">Role</label>
            <select class="form-select" id="{{ $roleSelectId }}" name="role" data-access-role>
                <option value="staff"{{ ($selectedRole ?? 'staff') === 'staff' ? ' selected' : '' }}>Staff</option>
                @if ($viewerIsOwner)
                    <option value="admin"{{ ($selectedRole ?? 'staff') === 'admin' ? ' selected' : '' }}>Admin</option>
                @endif
            </select>
            <p class="sr-hint">Staff work in the account. Admins can also manage Staff.</p>
        </div>

        <div class="sr-field">
            @if ($singleManageableBusiness !== null)
                {{-- Exactly one Business this person can grant: nothing to choose, so access is that one
                     Business only (selected scope — least privilege, never a hidden "all Businesses" grant). --}}
                <input type="hidden" name="business_access_scope" value="selected">
                <input type="hidden" name="business_uids[]" value="{{ $singleManageableBusiness['uid'] }}">
                <span class="sr-label">Business access</span>
                <p class="sr-hint mb-0" data-access-business-fixed="{{ $singleManageableBusiness['name'] }}">{{ $singleManageableBusiness['name'] }} only.</p>
            @else
                <label for="{{ $scopeSelectId }}">Business access</label>
                <select class="form-select" id="{{ $scopeSelectId }}" name="business_access_scope" data-access-scope>
                    <option value="all"{{ ($selectedScope ?? 'all') === 'all' ? ' selected' : '' }}>All Businesses</option>
                    <option value="selected"{{ ($selectedScope ?? 'all') === 'selected' ? ' selected' : '' }}>Selected Businesses</option>
                </select>
            @endif
        </div>
    </div>

    @if ($singleManageableBusiness === null)
        <div class="sr-bizpick" data-role="business-picker" data-access-picker @if (($selectedScope ?? 'all') === 'all') hidden @endif>
            @foreach ($manageableBusinesses ?? [] as $business)
                <label class="sr-bizpick__item">
                    <input type="checkbox" id="{{ $idp }}-biz-{{ $business['uid'] }}" name="business_uids[]" value="{{ $business['uid'] }}" data-label="{{ $business['name'] }}" @checked(in_array($business['uid'], $selectedBusinessUids ?? [], true))>
                    <span>{{ $business['name'] }}</span>
                </label>
            @endforeach
        </div>
    @endif

    <div class="sr-access__modules" data-role="visible-modules">
        <input type="hidden" name="modules_submitted" value="1">
        <div class="sr-access__modules-head">
            <span class="sr-label">Visible modules</span>
            <span class="sr-hint">Switch off what this person should not see. It only hides: it never gives access beyond their role or plan.</span>
        </div>
        <div class="sr-modules">
            @foreach ($modules as $module)
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="{{ $idp }}-module-{{ $module->value }}" name="visible_modules[]" value="{{ $module->value }}" data-label="{{ $module->label() }}" @checked(in_array($module->value, $selectedModules ?? \App\Enums\Workspace\WorkspaceMemberModule::values(), true))>
                    <label class="form-check-label" for="{{ $idp }}-module-{{ $module->value }}">{{ $module->label() }}<small class="d-block text-muted">{{ $module->hint() }}</small></label>
                </div>
            @endforeach
        </div>
    </div>

    <p class="sr-access__summary" data-access-summary aria-live="polite"></p>
</fieldset>
