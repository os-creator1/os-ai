{{--
    The detail / edit drawer for ONE directory listing (Bootstrap offcanvas) —
    the main per-directory workflow.

    Inputs: $row, $section, $canManage, $statuses, $workspaceUid,
            $businessUid, $business, $errors.

    Only controls that really exist are shown: the manual-record form (the one
    write route), Not applicable / Restore, "Open listing" (only from a stored,
    safe https URL), "Claim or update" (the directory's own verified page — the
    platform's, never editable here) and, for the Business's OWN custom
    directories only, rename / change link / archive. Nothing here fetches
    anything from the directory.

    The business-profile column is read-only context — the canonical data the
    directory SHOULD match, never copied into Citations.
--}}
@php
    use App\Enums\Seo\SeoNapFieldResult;
    use App\Library\Seo\SeoLinkSafety;

    $directory = $row->directory;
    $location = $section->location;
    $setup = $row->setupBadge();
    $nap = $row->napBadge();
    $importance = $row->importance();
    $mode = $row->trackingMode();
    $claimUrl = SeoLinkSafety::safeHttpsUrl($directory->claim_url);
    $errorBag = $errors->getBag('citation_' . $location->uid . '_' . $directory->key);
    $hasErrors = $errorBag->any();
    $drawerId = 'citation-drawer-' . $directory->key;
    $canEdit = $canManage && $row->writable;
    $notApplicable = $row->isNotApplicable();
    $verified = $row->citation?->last_verified_at;
    $canonical = $section->canonical;
    $websiteUrl = $section->canonicalWebsite;

    // Re-show the user's rejected input for THIS directory only.
    $val = fn (string $key, $stored) => $hasErrors ? old($key, $stored) : $stored;

    $compare = [
        ['Name', 'name', $canonical['name'], $row->listedName, $row->nap['name']],
        ['Phone', 'phone', $canonical['phone'], $row->listedPhone, $row->nap['phone']],
    ];
    if ($section->addressPermitted) {
        $compare[] = ['Address', 'address', $canonical['address'], $row->listedAddress, $row->nap['address']];
    }
    $compare[] = ['Website', 'website', $websiteUrl, $row->listedWebsite, $row->websiteResult];
@endphp
<div class="offcanvas offcanvas-end cz-drawer" tabindex="-1" id="{{ $drawerId }}" aria-labelledby="{{ $drawerId }}-label" data-role="citation-drawer" data-directory="{{ $directory->key }}" @if($hasErrors) data-open-on-load="1" @endif>
    <div class="offcanvas-header border-bottom">
        <div class="d-flex align-items-center gap-1 min-w-0">
            <span class="cz-dir-icon"><x-ds-icon :name="$directory->icon ?: 'map'" size="18" /></span>
            <div class="min-w-0">
                <h5 class="offcanvas-title mb-0" id="{{ $drawerId }}-label">{{ $directory->name }}</h5>
                <div class="d-flex flex-wrap gap-50 mt-25">
                    @if($row->isCustom())<x-badge variant="neutral">Custom</x-badge>@else<x-badge :variant="$importance->variant()">{{ $importance->label() }}</x-badge>@endif
                    <x-badge :variant="$setup['variant']" class="cz-badge"><x-ds-icon :name="$setup['icon']" size="12" />{{ $setup['label'] }}</x-badge>
                    @unless($notApplicable)<x-badge :variant="$nap['variant']" class="cz-badge"><x-ds-icon :name="$nap['icon']" size="12" />{{ $nap['label'] }}</x-badge>@endunless
                </div>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">
        <section data-role="drawer-about">
            <h6>About this directory</h6>
            <p class="mb-50">
                @if($row->isCustom())
                    A directory you added yourself. Business OS cannot check it; you record what it shows.
                @else
                    {{ $directory->setup_guidance ?: 'Make sure the name, address and phone shown here match your business profile.' }}
                @endif
            </p>
            @if($row->nicheGuidance !== null)
                <p class="mb-50" data-role="niche-guidance"><strong>{{ $row->nicheLabel }}:</strong> {{ $row->nicheGuidance }}</p>
            @endif
            <p class="text-caption mb-0" data-role="mode-note">
                <strong>{{ $mode->label() }}.</strong>
                @if($mode->isAutomatic())
                    Business OS reads this listing through an official connection.
                @else
                    Business OS does not read from {{ $directory->name }}: you claim the listing there and record what it shows. {{ $importance->label() }} is guidance, not a guarantee of results.
                @endif
            </p>
            <p class="cz-helper mb-0">{{ $row->helperText() }}</p>
        </section>

        @unless($row->writable && $section->writable)
            <p class="text-caption mb-0" data-role="drawer-readonly">
                {{ $section->writable ? 'This directory is no longer offered for this location, so your earlier record is kept for reference and is read-only.' : 'This location is archived, so its citations are read-only.' }}
            </p>
        @endunless

        <section>
            <h6>Business profile vs. this listing</h6>
            <table class="cz-compare" data-role="drawer-compare">
                <thead>
                    <tr><th></th><th>Business profile</th><th>Listing (recorded)</th></tr>
                </thead>
                <tbody>
                    @foreach($compare as [$label, $field, $canonicalValue, $listedValue, $result])
                        <tr data-field="{{ $field }}" data-result="{{ $result->value }}">
                            <th scope="row">{{ $label }}</th>
                            <td>{{ $canonicalValue ?: 'Not set' }}</td>
                            <td>
                                @if($listedValue !== null)
                                    {{ $listedValue }}
                                    @if($result === SeoNapFieldResult::Consistent)
                                        <x-badge variant="success" class="ms-50">Matches</x-badge>
                                    @elseif($result === SeoNapFieldResult::Mismatch)
                                        <x-badge variant="warning" class="ms-50">Differs</x-badge>
                                    @endif
                                @else
                                    <span class="cz-muted">{{ $result === SeoNapFieldResult::NotComparable && $field !== 'website' ? 'Not compared' : 'Not checked' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <th scope="row">Listing link</th>
                        <td></td>
                        <td>
                            @if($row->safeListingUrl !== null)
                                <span class="text-break">{{ $row->safeListingUrl }}</span>
                            @else
                                <span class="cz-muted">Not recorded</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Last checked</th>
                        <td></td>
                        <td>@if($verified !== null){{ $verified->format('M j, Y') }} <span class="cz-muted">(by you)</span>@if($row->reviewDue) <x-badge variant="warning" class="ms-50">Review recommended</x-badge>@endif @else<span class="cz-muted">Not checked</span>@endif</td>
                    </tr>
                </tbody>
            </table>
            @if(! $section->addressPermitted)
                <p class="text-caption mt-50 mb-0" data-role="address-withheld-drawer">This location does not publish a street address, so address is not compared.</p>
            @endif
            @if($row->citation?->notes !== null)
                <p class="text-caption mt-50 mb-0" data-role="citation-notes">Notes: {{ $row->citation->notes }}</p>
            @endif
        </section>

        <section>
            <h6>Directory links</h6>
            <div class="cz-drawer-links">
                @if($row->safeListingUrl !== null)
                    <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-50" href="{{ $row->safeListingUrl }}" target="_blank" rel="{{ SeoLinkSafety::EXTERNAL_REL }}" data-role="drawer-listing-link"><x-ds-icon name="external-link" size="14" />Open listing</a>
                @endif
                @if($claimUrl !== null)
                    <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-50" href="{{ $claimUrl }}" target="_blank" rel="{{ SeoLinkSafety::EXTERNAL_REL }}" data-role="drawer-claim-link"><x-ds-icon name="external-link" size="14" />Claim or update on {{ $directory->name }}</a>
                @endif
                @if($row->safeListingUrl === null && $claimUrl === null)
                    <span class="text-caption">No official claim link is recorded for this directory. Search for your business on {{ $directory->name }} and record its link below.</span>
                @endif
            </div>
        </section>

        @if($canEdit)
            <section>
                <h6>Record what the directory shows</h6>
                @foreach($errorBag->all() as $message)
                    <p class="text-danger mb-50" data-role="citation-error">{{ $message }}</p>
                @endforeach
                <form method="POST" action="{{ route('customer.workspaces.businesses.seo.citations.update', [$workspaceUid, $businessUid, $location->uid, $directory->key]) }}" data-role="citation-form">
                    @csrf
                    @method('PUT')
                    <div class="mb-1">
                        <label class="form-label" for="{{ $drawerId }}-status">Status</label>
                        <select id="{{ $drawerId }}-status" name="status" class="form-select">
                            @foreach($statuses as $status)
                                <option value="{{ $status->value }}" @selected($val('status', $row->status->value) === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="{{ $drawerId }}-url">Listing link (https)</label>
                        <input id="{{ $drawerId }}-url" type="text" name="listing_url" class="form-control" maxlength="2048" value="{{ $val('listing_url', $row->safeListingUrl) }}" placeholder="https://">
                    </div>
                    <div class="row g-1 mb-1">
                        <div class="col-12 col-sm-6">
                            <label class="form-label" for="{{ $drawerId }}-name">Name shown</label>
                            <input id="{{ $drawerId }}-name" type="text" name="listed_name" class="form-control" maxlength="191" value="{{ $val('listed_name', $row->listedName) }}">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label" for="{{ $drawerId }}-phone">Phone shown</label>
                            <input id="{{ $drawerId }}-phone" type="text" name="listed_phone" class="form-control" maxlength="50" value="{{ $val('listed_phone', $row->listedPhone) }}">
                        </div>
                    </div>
                    @if($section->addressPermitted)
                        <div class="mb-1">
                            <label class="form-label" for="{{ $drawerId }}-address">Address shown</label>
                            <input id="{{ $drawerId }}-address" type="text" name="listed_address" class="form-control" maxlength="255" value="{{ $hasErrors ? '' : $row->listedAddress }}">
                        </div>
                    @endif
                    <div class="mb-1">
                        <label class="form-label" for="{{ $drawerId }}-website">Website shown <span class="cz-muted">(if the directory shows one)</span></label>
                        <input id="{{ $drawerId }}-website" type="text" name="listed_website" class="form-control" maxlength="2048" value="{{ $val('listed_website', $row->listedWebsite) }}">
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="{{ $drawerId }}-checked">Date you last checked</label>
                        <input id="{{ $drawerId }}-checked" type="date" name="last_verified_at" class="form-control" value="{{ $val('last_verified_at', $verified?->format('Y-m-d')) }}">
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="{{ $drawerId }}-notes">Notes</label>
                        <textarea id="{{ $drawerId }}-notes" name="notes" class="form-control" maxlength="500" rows="2">{{ $val('notes', $row->citation?->notes) }}</textarea>
                    </div>
                    <div class="d-flex gap-1">
                        <button type="submit" class="btn btn-primary">Save details</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Cancel</button>
                    </div>
                </form>
            </section>

            <section data-role="drawer-applicability">
                <h6>{{ $notApplicable ? 'Restore this directory' : 'Not for you?' }}</h6>
                <form method="POST" action="{{ route('customer.workspaces.businesses.seo.citations.applicability', [$workspaceUid, $businessUid, $location->uid, $directory->key]) }}">
                    @csrf
                    <input type="hidden" name="applicable" value="{{ $notApplicable ? 1 : 0 }}">
                    <p class="text-caption">
                        @if($notApplicable)
                            It is out of your progress and "Needs attention" list. Restoring keeps everything you recorded.
                        @else
                            Mark it not applicable (for example, it does not serve your area or category). It leaves your progress and "Needs attention" list; what you recorded is kept and you can restore it any time.
                        @endif
                    </p>
                    <button type="submit" class="btn btn-sm btn-outline-secondary" data-role="applicability-button">{{ $notApplicable ? 'Restore' : 'Mark not applicable' }}</button>
                </form>
            </section>

            @if($row->isCustom())
                <section data-role="drawer-custom-edit">
                    <h6>Your custom directory</h6>
                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.citations.custom.update', [$workspaceUid, $businessUid, $location->uid, $directory->key]) }}" class="mb-1">
                        @csrf
                        @method('PUT')
                        <div class="mb-1">
                            <label class="form-label" for="{{ $drawerId }}-dname">Directory name</label>
                            <input id="{{ $drawerId }}-dname" type="text" name="name" class="form-control" maxlength="120" value="{{ $directory->name }}" required>
                        </div>
                        <div class="mb-1">
                            <label class="form-label" for="{{ $drawerId }}-dclaim">Claim / manage link (https, optional)</label>
                            <input id="{{ $drawerId }}-dclaim" type="text" name="claim_url" class="form-control" maxlength="2048" value="{{ $claimUrl }}" placeholder="https://">
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Save directory</button>
                    </form>
                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.citations.custom.archive', [$workspaceUid, $businessUid, $location->uid, $directory->key]) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-flat-secondary" data-role="archive-custom">Archive this directory</button>
                        <span class="text-caption">Your history is kept.</span>
                    </form>
                </section>
            @endif
        @endif
    </div>
</div>
