{{--
    Contract 18, Sub-slice 18E — Citations.

    The Business's OWN record of where it is listed, per Location. Nothing on
    this page is observed from a directory: the platform never fetches a
    listing URL and never contacts a directory, and no status is ever changed
    by a NAP difference — the comparison words are display only.

    Every value is rendered with escaped Blade output only; raw, unescaped
    output is forbidden in this view. An external link is rendered ONLY from a
    value that has passed SeoLinkSafety (https, no userinfo, valid host) and
    always carries rel="noopener noreferrer nofollow". A street address is
    rendered only where the Location is permitted to expose one.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'SEO — Citations')

@php
    use App\Library\Seo\SeoLinkSafety;

    $rel = SeoLinkSafety::EXTERNAL_REL;
@endphp

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">Citations</h4>
            <span class="text-caption">{{ $business->name }}</span>
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    <p class="text-caption mb-2" data-role="citations-note">
        Track where this business is listed, and compare what each listing shows with your business details.
        These are your own records — nothing here is checked against the directory, and a difference never changes a status.
    </p>

    @forelse($sections as $section)
        @php $location = $section->location; @endphp
        <x-card :padded="true" class="mb-2" data-section="citation-location" data-location="{{ $location->uid }}">
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-1">
                <p class="text-section-heading mb-0">{{ $location->name }}</p>
                @unless($section->writable)
                    <span class="badge bg-light text-dark" data-role="location-archived">Archived — read only</span>
                @endunless
            </div>

            <ul class="list-unstyled text-caption mb-1" data-role="canonical-nap">
                <li>Name: {{ $section->canonical['name'] ?? 'Not set' }}</li>
                <li>Phone: {{ $section->canonical['phone'] ?? 'Not set' }}</li>
                @if($section->addressPermitted)
                    <li>Address: {{ $section->canonical['address'] ?? 'Not set' }}</li>
                @else
                    <li data-role="address-withheld">Address: not published for this location, so it is not compared.</li>
                @endif
            </ul>

            @if($section->google !== null)
                <div class="py-50 border-bottom" data-role="google-row">
                    <strong>Google Business Profile</strong>
                    <span class="badge bg-light text-dark ms-1" data-role="google-state">{{ $section->google->bound ? 'Linked' : 'Not linked' }}</span>
                    @if($section->google->napMismatchCount !== null)
                        <span class="text-caption d-block" data-role="google-mismatch-count">{{ $section->google->napMismatchCount }} detail(s) differ from Google.</span>
                    @endif
                    <span class="text-caption d-block">Read-only. Managed in Google Business Profile.</span>
                </div>
            @endif

            @foreach($section->rows as $row)
                @php
                    $directory = $row->directory;
                    $claimUrl = SeoLinkSafety::safeHttpsUrl($directory->claim_url);
                    $errorBag = $errors->getBag('citation_' . $location->uid . '_' . $directory->key);
                @endphp
                <div class="py-1 @unless($loop->last) border-bottom @endunless" data-role="citation-row" data-directory="{{ $directory->key }}" data-status="{{ $row->status->value }}">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <strong>{{ $directory->name }}</strong>
                        <span class="badge bg-light text-dark" data-role="citation-status">{{ $row->status->label() }}</span>
                    </div>

                    @if($row->safeListingUrl !== null)
                        <a href="{{ $row->safeListingUrl }}" target="_blank" rel="{{ $rel }}" data-role="listing-link">View your listing</a>
                    @endif
                    @if($claimUrl !== null)
                        <a class="ms-1" href="{{ $claimUrl }}" target="_blank" rel="{{ $rel }}" data-role="claim-link">Claim or update on {{ $directory->name }}</a>
                    @endif

                    <ul class="list-unstyled text-caption mb-0" data-role="nap-results">
                        <li>Name: <span data-field="name" data-result="{{ $row->nap['name']->value }}">{{ $row->nap['name']->label() }}</span>@if($row->listedName !== null) — shows “{{ $row->listedName }}”@endif</li>
                        <li>Phone: <span data-field="phone" data-result="{{ $row->nap['phone']->value }}">{{ $row->nap['phone']->label() }}</span>@if($row->listedPhone !== null) — shows “{{ $row->listedPhone }}”@endif</li>
                        <li>Address: <span data-field="address" data-result="{{ $row->nap['address']->value }}">{{ $row->nap['address']->label() }}</span>@if($row->listedAddress !== null) — shows “{{ $row->listedAddress }}”@endif</li>
                    </ul>
                    @if($row->citation?->last_verified_at !== null)
                        <span class="text-caption d-block">You last checked this on {{ $row->citation->last_verified_at->format('Y-m-d') }}.</span>
                    @endif
                    @if($row->citation?->notes !== null)
                        <span class="text-caption d-block" data-role="citation-notes">{{ $row->citation->notes }}</span>
                    @endif

                    @if($canManage && $row->writable)
                        <details class="mt-50">
                            <summary class="text-caption">Edit</summary>
                            @foreach($errorBag->all() as $message)
                                <p class="text-danger mb-50" data-role="citation-error">{{ $message }}</p>
                            @endforeach
                            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.citations.update', [$workspaceUid, $businessUid, $location->uid, $directory->key]) }}" data-role="citation-form">
                                @csrf
                                @method('PUT')
                                <label class="d-block mb-50">Status
                                    <select name="status" class="form-select">
                                        @foreach($statuses as $status)
                                            <option value="{{ $status->value }}" @selected($row->status === $status)>{{ $status->label() }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="d-block mb-50">Listing link (https)
                                    <input type="text" name="listing_url" class="form-control" maxlength="2048" value="{{ $row->safeListingUrl }}">
                                </label>
                                <label class="d-block mb-50">Name shown
                                    <input type="text" name="listed_name" class="form-control" maxlength="191" value="{{ $row->listedName }}">
                                </label>
                                <label class="d-block mb-50">Phone shown
                                    <input type="text" name="listed_phone" class="form-control" maxlength="50" value="{{ $row->listedPhone }}">
                                </label>
                                @if($section->addressPermitted)
                                    <label class="d-block mb-50">Address shown
                                        <input type="text" name="listed_address" class="form-control" maxlength="255" value="{{ $row->listedAddress }}">
                                    </label>
                                @endif
                                <label class="d-block mb-50">Date you last checked
                                    <input type="date" name="last_verified_at" class="form-control" value="{{ $row->citation?->last_verified_at?->format('Y-m-d') }}">
                                </label>
                                <label class="d-block mb-50">Notes
                                    <textarea name="notes" class="form-control" maxlength="500" rows="2">{{ $row->citation?->notes }}</textarea>
                                </label>
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            </form>
                        </details>
                    @endif
                </div>
            @endforeach
        </x-card>
    @empty
        <x-card :padded="true" data-section="citation-empty">
            <p class="mb-0" data-role="citation-empty">There are no locations you can track citations for yet.</p>
        </x-card>
    @endforelse
@endsection
