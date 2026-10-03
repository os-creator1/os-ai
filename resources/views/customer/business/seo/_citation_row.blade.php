{{--
    One DIRECTORY row of the Citations listings. Generic by construction: it
    reads only the SeoCitationRow (directory, status, recorded values,
    comparison) — adding a directory later is a new seeded reference row, not
    a new template. The only per-directory presentation is the icon below.

    Inputs: $row, $section, $canManage, $icons, $workspaceUid, $businessUid.

    Rendered values are escaped. External links come ONLY from SeoLinkSafety
    results and always carry rel="noopener noreferrer nofollow".
--}}
@php
    use App\Library\Seo\SeoLinkSafety;

    $directory = $row->directory;
    $state = $row->displayState();
    $claimUrl = SeoLinkSafety::safeHttpsUrl($directory->claim_url);
    $verified = $row->citation?->last_verified_at;
    $drawerId = 'citation-drawer-' . $directory->key;
    $canEdit = $canManage && $row->writable;
@endphp
<div class="cz-row" data-role="citation-row" data-directory="{{ $directory->key }}" data-status="{{ $row->status->value }}" data-state="{{ $state->value }}" data-drawer="#{{ $drawerId }}">
    <div class="cz-c-dir cz-dir">
        <span class="cz-dir-icon"><x-ds-icon :name="$icons[$directory->key] ?? 'map'" size="18" /></span>
        <span class="min-w-0">
            <button type="button" class="cz-dir-name" data-bs-toggle="offcanvas" data-bs-target="#{{ $drawerId }}" aria-controls="{{ $drawerId }}">{{ $directory->name }}</button>
            <span class="cz-dir-kind">Manually tracked</span>
        </span>
    </div>

    <div class="cz-c-status">
        <x-badge :variant="$state->variant()" class="cz-badge" data-role="citation-status"><x-ds-icon :name="$state->icon()" size="12" />{{ $state->label() }}</x-badge>
        <p class="cz-helper" data-role="citation-helper">{{ $row->helperText() }}</p>
    </div>

    <div class="cz-c-name">@include('customer.business.seo._citation_field', ['label' => 'Name', 'field' => 'name', 'row' => $row, 'value' => $row->listedName])</div>
    <div class="cz-c-phone">@include('customer.business.seo._citation_field', ['label' => 'Phone', 'field' => 'phone', 'row' => $row, 'value' => $row->listedPhone])</div>
    <div class="cz-c-addr">
        @if($section->addressPermitted)
            @include('customer.business.seo._citation_field', ['label' => 'Address', 'field' => 'address', 'row' => $row, 'value' => $row->listedAddress])
        @else
            <div class="cz-field cz-field--none" data-field="address" data-result="not_comparable"><span class="cz-label">Address</span><x-ds-icon name="minus" size="14" /><span>Not published</span></div>
        @endif
    </div>

    <div class="cz-c-checked cz-checked" data-role="citation-checked">
        <span class="cz-label">Last checked</span>
        @if($verified !== null)
            <span>{{ $verified->format('M j, Y') }}</span>
            <span class="d-block text-caption">by you, manually</span>
        @else
            <span class="cz-muted">Not checked</span>
        @endif
    </div>

    <div class="cz-c-actions cz-row-actions">
        @if($canEdit)
            <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center justify-content-center gap-50" data-role="citation-edit" data-bs-toggle="offcanvas" data-bs-target="#{{ $drawerId }}">
                <x-ds-icon name="pencil" size="14" />{{ $row->hasRecordedDetails() ? 'Edit' : 'Add details' }}
            </button>
        @else
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="offcanvas" data-bs-target="#{{ $drawerId }}">Details</button>
        @endif
        @if($row->safeListingUrl !== null)
            <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center justify-content-center gap-50" href="{{ $row->safeListingUrl }}" target="_blank" rel="{{ SeoLinkSafety::EXTERNAL_REL }}" data-role="listing-link">
                <x-ds-icon name="external-link" size="14" />Open listing
            </a>
        @endif
        @if($claimUrl !== null)
            <a class="btn btn-sm btn-flat-secondary d-inline-flex align-items-center justify-content-center gap-50" href="{{ $claimUrl }}" target="_blank" rel="{{ SeoLinkSafety::EXTERNAL_REL }}" data-role="claim-link">
                <x-ds-icon name="external-link" size="14" />Claim or update
            </a>
        @endif
    </div>
</div>
