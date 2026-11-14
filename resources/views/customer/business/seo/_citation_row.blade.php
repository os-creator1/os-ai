{{--
    One DIRECTORY row of the inventory. Generic by construction: reads only the SeoCitationRow (directory,
    importance, tracking mode, status, recorded values, comparison). Collapsed: tile, name, status pill, last
    checked. Expanded: the recorded link, check date and notes, the per-field comparison with the business
    profile, and the working actions (official link / recorded listing link, and the existing record drawer).
    Opening a directory link never changes a status.
--}}
@php
    use App\Enums\Seo\SeoCitationDisplayState;
    use App\Library\Seo\SeoCitationNextAction;
    use App\Library\Seo\SeoCitationPresentation;
    use App\Library\Seo\SeoLinkSafety;

    $directory = $row->directory;
    $state = $row->displayState();
    $setup = $row->setupBadge();
    $nap = $row->napBadge();
    $importance = $row->importance();
    $verified = $row->citation?->last_verified_at;
    $drawerId = 'citation-drawer-' . $directory->key;
    $canEdit = $canManage && $row->writable;
    $notApplicable = $row->isNotApplicable();
    $mode = $row->trackingMode();
    $bucket = SeoCitationPresentation::bucket($row);
    [$letter, $tileBg, $tileFg] = SeoCitationPresentation::tile($directory->key, $directory->name);
    $pill = match ($bucket) {
        'listed' => ['cg-p-green', $setup['label']],
        'progress' => ['cg-p-blue', 'In progress'],
        'attention' => ['cg-p-amber', 'Needs attention'],
        'unchecked' => ['cg-p-line', 'Not checked'],
        'na' => ['cg-p-grey', 'Not applicable'],
        default => ['cg-p-grey', 'History'],
    };
    $action = SeoCitationNextAction::directoryAction($row, $canManage);
    $officialUrl = SeoLinkSafety::safeHttpsUrl($directory->claim_url);
    $sub = $row->isCustom() ? 'Custom directory' : ($row->safeListingUrl !== null ? 'Recorded by you' : $mode->label());
    $noDetailsYet = ! $notApplicable && ! $row->hasRecordedDetails() && $state === SeoCitationDisplayState::NotStarted;
@endphp
<div class="cg-row" data-role="citation-row" data-directory="{{ $directory->key }}" data-status="{{ $row->status->value }}" data-state="{{ $state->value }}" data-setup="{{ $row->status->value }}" data-importance="{{ $importance->value }}" data-custom="{{ $row->isCustom() ? '1' : '0' }}" data-notchecked="{{ $row->isNotChecked() ? '1' : '0' }}" data-attention="{{ $row->needsAttention() ? '1' : '0' }}" data-setup-needed="{{ $row->needsSetup() ? '1' : '0' }}" data-bucket="{{ $bucket }}" data-niche="{{ $row->nicheLabel !== null && ! $row->isHistoryOnly() ? '1' : '0' }}" data-name="{{ \Illuminate\Support\Str::lower($directory->name) }}" data-drawer="#{{ $drawerId }}">
    <button type="button" aria-expanded="false" data-cg-row-toggle>
        <span class="cg-nm">
            <span class="cg-tile" style="background:{{ $tileBg }};color:{{ $tileFg }}" aria-hidden="true">{{ $letter }}</span>
            <span style="min-width:0">
                <b>{{ $directory->name }}</b>
                <small>{{ $sub }}
                    @if($row->isCustom())<span data-role="custom-badge"></span>@endif
                    @if($row->nicheLabel !== null && ! $row->isHistoryOnly())<span data-role="niche-badge">· {{ $row->nicheLabel }}</span>@endif
                    @if($row->isHistoryOnly())<span data-role="history-badge">· History</span>@endif
                </small>
            </span>
        </span>
        <span><span class="cg-pill {{ $pill[0] }}" data-role="citation-status" data-setup-label="{{ $setup['label'] }}"><i></i>{{ $pill[1] }}</span></span>
        <span class="cg-lc" data-role="citation-checked">
            @if($verified !== null)Checked {{ $verified->format($verified->isCurrentYear() ? 'M j' : 'M j, Y') }}@else Never checked @endif
        </span>
        <svg class="cg-ic cg-ch" viewBox="0 0 24 24"><use href="#cg-i-down"/></svg>
    </button>
    <div class="cg-det">
        <div class="cg-det-grid">
            <div><small>Listing link</small><span>@if($row->safeListingUrl !== null){{ $row->safeListingUrl }}@else None saved @endif</span></div>
            <div><small>Last checked</small><span>@if($verified !== null){{ $verified->format('M j, Y') }}@if($row->reviewDue) <span class="cg-pill cg-p-amber" data-role="review-due">Review recommended</span>@endif @else Not yet @endif</span></div>
            <div><small>Notes</small><span>{{ filled($row->citation?->notes) ? $row->citation->notes : '—' }}</span></div>
        </div>

        <p class="cg-note-inline" data-role="citation-helper" style="margin:0 0 10px">{{ $row->helperText() }}</p>

        @if(! $notApplicable && $row->hasRecordedDetails())
            <p style="margin:0 0 10px"><span class="cg-pill {{ $nap['variant'] === 'warning' ? 'cg-p-amber' : ($nap['variant'] === 'success' ? 'cg-p-green' : 'cg-p-line') }}" data-role="nap-status"><i></i>{{ $nap['label'] }}</span></p>
        @endif

        <div class="cg-nap-line">
            @if(! $row->hasRecordedDetails())
                <span class="cg-note-inline" data-role="nap-empty" @unless($noDetailsYet) aria-label="Nothing recorded yet" @endunless>{{ $noDetailsYet ? $row->helperText() : '—' }}</span>
            @else
                @include('customer.business.seo._citation_field', ['label' => 'Name', 'field' => 'name', 'row' => $row, 'value' => $row->listedName])
                @include('customer.business.seo._citation_field', ['label' => 'Phone', 'field' => 'phone', 'row' => $row, 'value' => $row->listedPhone])
                @if($section->addressPermitted)
                    @include('customer.business.seo._citation_field', ['label' => 'Address', 'field' => 'address', 'row' => $row, 'value' => $row->listedAddress])
                @else
                    <div class="cg-field cg-field--none" data-field="address" data-result="not_comparable"><span class="cg-label">Address</span><span>Not published</span></div>
                @endif
            @endif
        </div>

        <div class="cg-acts">
            @if($row->safeListingUrl !== null)
                <a class="cg-btn {{ $canEdit ? 'cg-ghost' : 'cg-primary' }} cg-sm" href="{{ $row->safeListingUrl }}" target="_blank" rel="{{ SeoLinkSafety::EXTERNAL_REL }}" data-role="listing-link" aria-label="Open {{ $directory->name }} listing">Review existing listing<svg class="cg-ic" viewBox="0 0 24 24" style="width:14px;height:14px"><use href="#cg-i-ext"/></svg></a>
            @endif
            @if($officialUrl !== null && ! $row->isHistoryOnly())
                <a class="cg-btn cg-ghost cg-sm" href="{{ $officialUrl }}" target="_blank" rel="{{ SeoLinkSafety::EXTERNAL_REL }}" data-role="official-link">{{ SeoCitationNextAction::setupLabel($row) }}<svg class="cg-ic" viewBox="0 0 24 24" style="width:14px;height:14px"><use href="#cg-i-ext"/></svg></a>
            @endif
            <button type="button" class="cg-btn {{ $canEdit && $row->safeListingUrl === null && $officialUrl === null ? 'cg-primary' : 'cg-ghost' }} cg-sm" data-role="{{ $canEdit ? 'citation-edit' : 'citation-details' }}" data-bs-toggle="offcanvas" data-bs-target="#{{ $drawerId }}"><svg class="cg-ic" viewBox="0 0 24 24" style="width:14px;height:14px"><use href="#cg-i-edit"/></svg>{{ $canEdit ? ($row->hasRecordedDetails() ? 'Update record' : 'Record what you find') : 'Details' }}</button>
            <span class="cg-hint">Opening a link doesn't mark it listed</span>
        </div>
    </div>
</div>
