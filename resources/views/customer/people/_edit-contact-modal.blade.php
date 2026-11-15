{{--
    "Edit contact" modal (approved design ContactEdit.dc.html). One modal per page, opened by anything marked
    [data-mgx-edit-contact][data-uid]. Behaviour lives in public/js/crm/mgx-contact-edit.js: it GETs the contact's details
    (people.edit), saves with POST people.update and deletes with POST people.destroy. Every one of those routes re-checks the
    Business, the Location ACL and the permission; this markup only presents. Without JavaScript the trigger keeps its own
    href (the existing edit page / profile).

    Expects nothing: the workspace and business come from the current route.
--}}
@can('update_contact')
@php
    $workspaceUid = request()->route('workspaceUid');
    $businessUid = request()->route('businessUid');
    $editConfig = [
        'editUrl' => route('customer.workspaces.businesses.people.edit', [$workspaceUid, $businessUid, '__UID__']),
        'csrf' => csrf_token(),
        'defaultRegion' => \App\Library\Contacts\CountryDirectory::DEFAULT_REGION,
        'countries' => \App\Library\Contacts\CountryDirectory::all(),
    ];
@endphp
<div class="mgx-modal is-edit" id="mgx-edit-contact" hidden data-config="{{ json_encode($editConfig, JSON_UNESCAPED_UNICODE) }}">
    <section class="mgx-dialog" role="dialog" aria-modal="true" aria-labelledby="mgxEcT" tabindex="-1">
        <div class="mgx-dh">
            <div><h2 id="mgxEcT">Edit contact</h2><span>Update any details, then save.</span></div>
            <button type="button" class="mgx-x" aria-label="Close" data-mgx-close><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <form novalidate autocomplete="off" data-mgx-form>
            <fieldset class="mgx-fs" data-mgx-fields disabled>
                <div class="mgx-db">
                    <p class="mgx-status" role="status" aria-live="polite" data-mgx-loading hidden></p>
                    <div class="mgx-fl">
                        <label for="mgx-e-phone">Phone <span class="req">*</span></label>
                        <div class="mgx-ph">
                            <button type="button" class="mgx-cc" aria-label="Country code" aria-haspopup="listbox" aria-expanded="false" data-mgx-cc>
                                <span class="mgx-flag" data-mgx-flag></span><span data-mgx-code>+1</span>
                                <svg class="mgx-ic" viewBox="0 0 24 24" style="width:14px;height:14px;color:#8A817C"><path d="M7 10l5 5 5-5"/></svg>
                            </button>
                            <input class="mgx-in" id="mgx-e-phone" name="phone" type="tel" inputmode="tel" aria-describedby="mgx-e-phone-err" data-mgx-phone>
                        </div>
                        <p class="mgx-err" id="mgx-e-phone-err" role="alert" data-mgx-err="phone"></p>
                        <div class="mgx-pick" hidden data-mgx-pick>
                            <input type="search" placeholder="Search country or code" aria-label="Search countries" data-mgx-pick-search>
                            <div class="mgx-list" role="listbox" aria-label="Countries" data-mgx-list></div>
                        </div>
                    </div>
                    <div class="mgx-row2">
                        <div class="mgx-fl"><label for="mgx-e-first">First name</label><input class="mgx-in" id="mgx-e-first" name="first_name" maxlength="120"></div>
                        <div class="mgx-fl"><label for="mgx-e-last">Last name</label><input class="mgx-in" id="mgx-e-last" name="last_name" maxlength="120"></div>
                    </div>
                    <div class="mgx-fl">
                        <label for="mgx-e-email">Email <span>(optional)</span></label>
                        <input class="mgx-in" id="mgx-e-email" name="email" type="email" maxlength="191" aria-describedby="mgx-e-email-err">
                        <p class="mgx-err" id="mgx-e-email-err" role="alert" data-mgx-err="email"></p>
                    </div>
                    <div class="mgx-fl">
                        <label for="mgx-e-company">Company <span>(optional)</span></label>
                        <input class="mgx-in" id="mgx-e-company" name="company" maxlength="191" placeholder="Company name">
                    </div>
                    <div class="mgx-fl">
                        <label for="mgx-e-group">Group</label>
                        <div class="mgx-sel">
                            <select class="mgx-in" id="mgx-e-group" name="group" aria-describedby="mgx-e-group-err" data-mgx-group></select>
                            <svg class="mgx-ic" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg>
                        </div>
                        <p class="mgx-err" id="mgx-e-group-err" role="alert" data-mgx-err="group"></p>
                    </div>
                    <div class="mgx-fl">
                        <label for="mgx-e-notes">Notes <span>(optional)</span></label>
                        <textarea class="mgx-in" id="mgx-e-notes" name="notes" maxlength="5000"></textarea>
                    </div>
                </div>
            </fieldset>
            <div class="mgx-df" data-mgx-footer>
                <p class="mgx-status" role="status" aria-live="polite" data-mgx-status hidden></p>
                <button type="button" class="mgx-del" data-mgx-delete hidden>Delete contact</button>
                <span class="mgx-del-confirm" data-mgx-confirm hidden>
                    <span>Delete this contact for good?</span>
                    <button type="button" class="mgx-btn is-danger" data-mgx-delete-yes>Yes, delete</button>
                    <button type="button" class="mgx-btn" style="color:#5E5652;background:transparent" data-mgx-delete-no>Keep</button>
                </span>
                <span class="mgx-df-spacer"></span>
                <button type="button" class="mgx-btn" style="color:#5E5652;background:transparent" data-mgx-close>Cancel</button>
                <button type="submit" class="mgx-btn is-primary" style="padding:0 20px" data-mgx-save disabled>Save changes</button>
            </div>
        </form>
    </section>
</div>
<script src="{{ asset('js/crm/mgx-contact-edit.js') }}?v=1" defer></script>
@endcan
