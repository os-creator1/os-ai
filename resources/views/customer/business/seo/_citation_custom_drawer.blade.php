{{--
    "Add custom directory" drawer. A custom directory belongs to this Business
    only (optionally to just this Location), is always manually tracked, and is
    labelled Custom — it can never look like a platform-certified source.

    Inputs: $section, $statuses, $workspaceUid, $businessUid, $errors.
--}}
@php
    $location = $section->location;
    $errorBag = $errors->getBag('citation_' . $location->uid . '_new_custom');
    $hasErrors = $errorBag->any();
    $old = fn (string $key, $default = null) => $hasErrors ? old($key, $default) : $default;
@endphp
<div class="offcanvas offcanvas-end cz-drawer" tabindex="-1" id="citation-drawer-new-custom" aria-labelledby="citation-drawer-new-custom-label" data-role="custom-drawer" @if($hasErrors) data-open-on-load="1" @endif>
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="citation-drawer-new-custom-label">Add a custom directory</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <p class="text-caption mb-0">For a directory or listing that matters to your business and is not in the list above. It is manually tracked: you record what it shows, and Business OS compares it with your business profile.</p>

        @foreach($errorBag->all() as $message)
            <p class="text-danger mb-0" data-role="citation-error">{{ $message }}</p>
        @endforeach

        <form method="POST" action="{{ route('customer.workspaces.businesses.seo.citations.custom.store', [$workspaceUid, $businessUid, $location->uid]) }}" data-role="custom-form">
            @csrf
            <div class="mb-1">
                <label class="form-label" for="custom-name">Directory name</label>
                <input id="custom-name" type="text" name="name" class="form-control" maxlength="120" value="{{ $old('name') }}" required>
            </div>
            <div class="mb-1">
                <label class="form-label" for="custom-url">Your listing link (https)</label>
                <input id="custom-url" type="text" name="listing_url" class="form-control" maxlength="2048" value="{{ $old('listing_url') }}" placeholder="https://">
            </div>
            <div class="mb-1">
                <label class="form-label" for="custom-claim">Claim / manage link (https, optional)</label>
                <input id="custom-claim" type="text" name="claim_url" class="form-control" maxlength="2048" value="{{ $old('claim_url') }}" placeholder="https://">
            </div>
            <div class="mb-1">
                <label class="form-label" for="custom-status">Status</label>
                <select id="custom-status" name="status" class="form-select">
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}" @selected($old('status', 'not_started') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="row g-1 mb-1">
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="custom-listed-name">Name shown</label>
                    <input id="custom-listed-name" type="text" name="listed_name" class="form-control" maxlength="191" value="{{ $old('listed_name') }}">
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="custom-listed-phone">Phone shown</label>
                    <input id="custom-listed-phone" type="text" name="listed_phone" class="form-control" maxlength="50" value="{{ $old('listed_phone') }}">
                </div>
            </div>
            @if($section->addressPermitted)
                <div class="mb-1">
                    <label class="form-label" for="custom-listed-address">Address shown</label>
                    <input id="custom-listed-address" type="text" name="listed_address" class="form-control" maxlength="255" value="">
                </div>
            @endif
            <div class="mb-1">
                <label class="form-label" for="custom-listed-website">Website shown <span class="cz-muted">(optional)</span></label>
                <input id="custom-listed-website" type="text" name="listed_website" class="form-control" maxlength="2048" value="{{ $old('listed_website') }}">
            </div>
            <div class="mb-1">
                <label class="form-label" for="custom-checked">Date you last checked</label>
                <input id="custom-checked" type="date" name="last_verified_at" class="form-control" value="{{ $old('last_verified_at') }}">
            </div>
            <div class="mb-1">
                <label class="form-label" for="custom-notes">Notes</label>
                <textarea id="custom-notes" name="notes" class="form-control" maxlength="500" rows="2">{{ $old('notes') }}</textarea>
            </div>
            <div class="mb-1">
                <label class="form-label" for="custom-scope">Applies to</label>
                <select id="custom-scope" name="location_scope" class="form-select">
                    {{-- A directory shared by every location may be added (and later changed) only by someone with access to every location. --}}
                    @if($section->canEditSharedDirectories)
                        <option value="all" @selected($old('location_scope', 'all') === 'all')>All my locations</option>
                    @endif
                    <option value="this" @selected($old('location_scope', $section->canEditSharedDirectories ? 'all' : 'this') === 'this')>Only {{ $location->name }}</option>
                </select>
            </div>
            <div class="d-flex gap-1">
                <button type="submit" class="btn btn-primary">Add directory</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Cancel</button>
            </div>
        </form>
    </div>
</div>
