{{--
    "New contact" modal (approved design ContactsList.dc.html). Posts JSON to the Business-scoped people.store route;
    behaviour lives in public/js/crm/mgx-contact-modal.js. Without JavaScript the "Add contact" button is still a link
    to the existing add form.
--}}
@php
    $workspaceUid = request()->route('workspaceUid');
    $businessUid = request()->route('businessUid');
    $config = [
        'storeUrl' => route('customer.workspaces.businesses.people.store', [$workspaceUid, $businessUid]),
        'csrf' => csrf_token(),
        'optionsUrl' => route('customer.workspaces.businesses.people.new-contact-options', [$workspaceUid, $businessUid]),
        'defaultRegion' => $modal['defaultRegion'],
        'countries' => $modal['countries'],
    ];
@endphp
<div class="mgx-modal" id="mgx-new-contact" hidden data-config="{{ json_encode($config, JSON_UNESCAPED_UNICODE) }}">
    <section class="mgx-dialog" role="dialog" aria-modal="true" aria-labelledby="mgxNcT" tabindex="-1">
        <div class="mgx-dh">
            <div><h2 id="mgxNcT">New contact</h2><span>Only the phone number is required.</span></div>
            <button type="button" class="mgx-x" aria-label="Close" data-mgx-close><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </div>
        <form novalidate autocomplete="off" data-mgx-form>
            <div class="mgx-db">
                <div class="mgx-fl">
                    <label for="mgx-phone">Phone <span class="req">*</span></label>
                    <div class="mgx-ph">
                        <button type="button" class="mgx-cc" aria-label="Country code" aria-haspopup="listbox" aria-expanded="false" data-mgx-cc>
                            <span class="mgx-flag" data-mgx-flag></span><span data-mgx-code>+1</span>
                            <svg class="mgx-ic" viewBox="0 0 24 24" style="width:14px;height:14px;color:#8A817C"><path d="M7 10l5 5 5-5"/></svg>
                        </button>
                        <input class="mgx-in" id="mgx-phone" name="phone" type="tel" inputmode="tel" placeholder="(720) 664-0969" aria-describedby="mgx-phone-err" data-mgx-phone>
                    </div>
                    <p class="mgx-err" id="mgx-phone-err" role="alert" data-mgx-err="phone"></p>
                    <div class="mgx-pick" hidden data-mgx-pick>
                        <input type="search" placeholder="Search country or code" aria-label="Search countries" data-mgx-pick-search>
                        <div class="mgx-list" role="listbox" aria-label="Countries" data-mgx-list></div>
                    </div>
                </div>
                <div class="mgx-row2">
                    <div class="mgx-fl"><label for="mgx-first">First name</label><input class="mgx-in" id="mgx-first" name="first_name" placeholder="First name" maxlength="120"></div>
                    <div class="mgx-fl"><label for="mgx-last">Last name</label><input class="mgx-in" id="mgx-last" name="last_name" placeholder="Last name" maxlength="120"></div>
                </div>
                <div class="mgx-fl">
                    <label for="mgx-email">Email <span>(optional)</span></label>
                    <input class="mgx-in" id="mgx-email" name="email" type="email" placeholder="name@example.com" maxlength="191" aria-describedby="mgx-email-err">
                    <p class="mgx-err" id="mgx-email-err" role="alert" data-mgx-err="email"></p>
                </div>
                <div class="mgx-fl">
                    <label for="mgx-group">Group</label>
                    <div class="mgx-sel">
                        <select class="mgx-in" id="mgx-group" name="group" disabled data-mgx-group><option value="">Loading groups…</option></select>
                        <svg class="mgx-ic" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg>
                    </div>
                    <p class="mgx-err" role="alert" data-mgx-err="group"></p>
                </div>
                <div class="mgx-fl" hidden data-mgx-location-wrap>
                    <label for="mgx-location">Location <span class="req">*</span></label>
                    <div class="mgx-sel">
                        <select class="mgx-in" id="mgx-location" name="location" data-mgx-location></select>
                        <svg class="mgx-ic" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg>
                    </div>
                    <p class="mgx-err" role="alert" data-mgx-err="location"></p>
                </div>
                <div class="mgx-fl">
                    <label for="mgx-notes">Notes <span>(optional)</span></label>
                    <textarea class="mgx-in" id="mgx-notes" name="notes" placeholder="Event date, venue, how they found you…" maxlength="5000"></textarea>
                </div>
            </div>
            <div class="mgx-df">
                <p class="mgx-status" role="status" data-mgx-status hidden></p>
                <label><input type="checkbox" name="another" data-mgx-another>Add another after saving</label>
                <button type="button" class="mgx-btn" style="color:#5E5652;background:transparent" data-mgx-close>Cancel</button>
                <button type="submit" class="mgx-btn is-primary" style="padding:0 20px" data-mgx-save>Save contact</button>
            </div>
        </form>
    </section>
</div>
<script src="{{ asset('js/crm/mgx-contact-modal.js') }}?v=1" defer></script>
