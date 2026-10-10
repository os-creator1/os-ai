{{--
    One DIRECTORY row of the Citations listings. Generic by construction: it
    reads only the SeoCitationRow (directory, importance, tracking mode,
    status, recorded values, comparison) — a new catalog directory needs no
    template change; the only per-directory presentation is its `icon` column.

    Inputs: $row, $section, $canManage, $workspaceUid, $businessUid.

    The SETUP badge is the owner's own progress; the NAP badge says whether
    what they recorded matches the business profile, and appears only once
    something has been recorded (a directory with nothing recorded is simply
    "Not checked" in the last-checked column). They are never merged into one
    word. Each row has ONE primary action; the directory's own claim link
    lives in the drawer it opens.

    Rendered values are escaped. External links come ONLY from SeoLinkSafety
    results and always carry rel="noopener noreferrer nofollow".
--}}
@php
    use App\Enums\Seo\SeoCitationDisplayState;
    use App\Library\Seo\SeoLinkSafety;

    $directory = $row->directory;
    $state = $row->displayState();
    $setup = $row->setupBadge();
    $nap = $row->napBadge();
    $importance = $row->importance();
    $claimUrl = SeoLinkSafety::safeHttpsUrl($directory->claim_url);
    $verified = $row->citation?->last_verified_at;
    $drawerId = 'citation-drawer-' . $directory->key;
    $canEdit = $canManage && $row->writable;
    $notApplicable = $row->isNotApplicable();
    $mode = $row->trackingMode();
    // An unrecorded listing says so in the details column, once, not under the status badge too.
    $noDetailsYet = ! $notApplicable && ! $row->hasRecordedDetails() && $state === SeoCitationDisplayState::NotStarted;

    $primaryLabel = match (true) {
        ! $canEdit => 'Details',
        $row->needsAttention() => 'Review',
        $state === SeoCitationDisplayState::InProgress => 'Update',
        $row->hasRecordedDetails() => 'Edit',
        $claimUrl !== null => 'Claim',
        default => 'Record details',
    };
@endphp
<div class="cz-row @if($notApplicable) cz-row--muted @endif" data-role="citation-row" data-directory="{{ $directory->key }}" data-status="{{ $row->status->value }}" data-state="{{ $state->value }}" data-setup="{{ $row->status->value }}" data-importance="{{ $importance->value }}" data-custom="{{ $row->isCustom() ? '1' : '0' }}" data-notchecked="{{ $row->isNotChecked() ? '1' : '0' }}" data-attention="{{ $row->needsAttention() ? '1' : '0' }}" data-setup-needed="{{ $row->needsSetup() ? '1' : '0' }}" data-name="{{ \Illuminate\Support\Str::lower($directory->name) }}" data-drawer="#{{ $drawerId }}">
    <div class="cz-c-dir cz-dir">
        <span class="cz-dir-icon"><x-ds-icon :name="$directory->icon ?: 'map'" size="18" /></span>
        <span class="min-w-0">
            <button type="button" class="cz-dir-name" data-bs-toggle="offcanvas" data-bs-target="#{{ $drawerId }}" aria-controls="{{ $drawerId }}">{{ $directory->name }}</button>
            <span class="cz-dir-kind">
                @if($row->isCustom())
                    <x-badge variant="neutral" data-role="custom-badge">Custom</x-badge>
                @else
                    <x-badge :variant="$importance->variant()" data-role="importance-badge">{{ $importance->label() }}</x-badge>
                @endif
                @if($row->nicheLabel !== null && ! $row->isHistoryOnly())<x-badge variant="accent" data-role="niche-badge">{{ $row->nicheLabel }}</x-badge>@endif
                @if($row->isHistoryOnly())<x-badge variant="neutral" data-role="history-badge">History</x-badge>@endif
                <span class="cz-mode" data-role="tracking-mode">{{ $mode->label() }}</span>
            </span>
        </span>
    </div>

    <div class="cz-c-status">
        <x-badge :variant="$setup['variant']" class="cz-badge" data-role="citation-status" data-setup-label="{{ $setup['label'] }}"><x-ds-icon :name="$setup['icon']" size="12" />{{ $setup['label'] }}</x-badge>
        @if(! $notApplicable && $row->hasRecordedDetails())
            <x-badge :variant="$nap['variant']" class="cz-badge mt-25" data-role="nap-status"><x-ds-icon :name="$nap['icon']" size="12" />{{ $nap['label'] }}</x-badge>
        @endif
        @unless($noDetailsYet)
            <p class="cz-helper" data-role="citation-helper">{{ $row->helperText() }}</p>
        @endunless
    </div>

    <div class="cz-c-nap">
        @if(! $row->hasRecordedDetails())
            @if($noDetailsYet)
                <span class="cz-muted" data-role="nap-empty">{{ $row->helperText() }}</span>
            @else
                <span class="cz-muted" data-role="nap-empty" aria-label="Nothing recorded yet">—</span>
            @endif
        @else
        @include('customer.business.seo._citation_field', ['label' => 'Name', 'field' => 'name', 'row' => $row, 'value' => $row->listedName])
        @include('customer.business.seo._citation_field', ['label' => 'Phone', 'field' => 'phone', 'row' => $row, 'value' => $row->listedPhone])
        @if($section->addressPermitted)
            @include('customer.business.seo._citation_field', ['label' => 'Address', 'field' => 'address', 'row' => $row, 'value' => $row->listedAddress])
        @else
            <div class="cz-field cz-field--none" data-field="address" data-result="not_comparable"><span class="cz-label">Address</span><x-ds-icon name="minus" size="14" /><span>Not published</span></div>
        @endif
        @endif
    </div>

    <div class="cz-c-checked cz-checked" data-role="citation-checked">
        <span class="cz-label">Last checked</span>
        @if($verified !== null)
            <span>{{ $verified->format('M j, Y') }}</span>
            @if($row->reviewDue)
                <x-badge variant="warning" class="mt-25" data-role="review-due">Review recommended</x-badge>
            @endif
        @else
            <span class="cz-muted">Not checked</span>
        @endif
    </div>

    <div class="cz-c-actions cz-row-actions">
        @if($row->safeListingUrl !== null)
            <a class="btn btn-sm btn-flat-secondary btn-icon" href="{{ $row->safeListingUrl }}" target="_blank" rel="{{ SeoLinkSafety::EXTERNAL_REL }}" data-role="listing-link" aria-label="Open {{ $directory->name }} listing" title="Open listing">
                <x-ds-icon name="external-link" size="14" />
            </a>
        @endif
        <button type="button" class="btn btn-sm {{ $row->needsAttention() && $canEdit ? 'btn-primary' : 'btn-outline-primary' }}" data-role="{{ $canEdit ? 'citation-edit' : 'citation-details' }}" data-bs-toggle="offcanvas" data-bs-target="#{{ $drawerId }}">{{ $primaryLabel }}</button>
    </div>
</div>
