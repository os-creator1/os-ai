{{-- The synthetic, read-only Google Business Profile row. Inputs: $section, $gbpUrl. State comes only from the
     GBP read model via the section; nothing here is stored or described as verified beyond what Google supplied. --}}
@php
    use App\Enums\Seo\SeoNapFieldResult;
    use App\Library\Seo\SeoCitationLocationSection;
    use App\Library\Seo\SeoCitationPresentation;

    $googleState = $section->googleState();
    $google = $section->google;
    $googleNap = $section->googleNap;
    [$gPill, $gLabel, $gCopy] = match ($googleState) {
        SeoCitationLocationSection::GOOGLE_CONNECTED => $section->googleHealthProblem() !== null
            ? ['cg-p-amber', 'Needs attention', $section->googleHealthProblem()]
            : ['cg-p-green', 'Connected', $section->googleCheckedAutomatically() ? 'Checked automatically' : 'Linked to your Google listing.'],
        SeoCitationLocationSection::GOOGLE_CONNECTION_LOST => ['cg-p-amber', 'Needs attention', 'The Google connection is not active.'],
        default => ['cg-p-line', 'Not linked', 'Not linked to a Google listing yet. Nothing to do if this business has no Google listing.'],
    };
    $gDifferingFields = $section->googleDifferingFields();
    $gDiffering = array_merge(array_map('ucfirst', $gDifferingFields), $section->googleOtherDifferences());
    $gAttention = $section->googleNeedsAttention();
    $gAccurate = $googleNap !== null && $gDifferingFields === [] && ! $gAttention && in_array(SeoNapFieldResult::Consistent, $googleNap, true);
    $gDataState = $gAccurate ? 'accurate' : ($gAttention ? 'needs_attention' : ($googleState === SeoCitationLocationSection::GOOGLE_NOT_LINKED ? 'not_started' : 'listed'));
    $gAsOf = $google?->healthAsOf;
    $gBucket = SeoCitationPresentation::googleBucket($section);
    [$gLetter, $gBg, $gFg] = SeoCitationPresentation::tile('google', 'Google');
@endphp
<div class="cg-row" data-role="google-row" data-google-state="{{ $googleState }}" data-importance="essential" data-custom="0" data-notchecked="{{ $googleNap === null ? '1' : '0' }}" data-attention="{{ $gAttention ? '1' : '0' }}" data-setup-needed="0" data-state="{{ $gDataState }}" data-bucket="{{ $gBucket }}" data-niche="0" data-name="google business profile">
    <button type="button" aria-expanded="false" data-cg-row-toggle>
        <span class="cg-nm">
            <span class="cg-tile" style="background:{{ $gBg }};color:{{ $gFg }}" aria-hidden="true">{{ $gLetter }}</span>
            <span style="min-width:0"><b>Google Business Profile</b><small>{{ $googleState === SeoCitationLocationSection::GOOGLE_NOT_LINKED ? 'Not connected' : 'Checked through your connection' }}</small></span>
        </span>
        <span><span class="cg-pill {{ $gPill }}" data-role="google-state"><i></i>{{ $gLabel }}</span></span>
        <span class="cg-lc" data-role="google-checked">
            @if($googleNap !== null && $gAsOf !== null)Checked {{ $gAsOf->format('M j, Y') }}@elseif($section->googleCheckedAutomatically())Checked automatically@else Not checked @endif
        </span>
        <svg class="cg-ic cg-ch" viewBox="0 0 24 24"><use href="#cg-i-down"/></svg>
    </button>
    <div class="cg-det">
        <p class="cg-note-inline" style="margin:0 0 10px">{{ $gCopy }}</p>
        @if($googleState === SeoCitationLocationSection::GOOGLE_NOT_LINKED)
            <p class="cg-note-inline" style="margin:0 0 14px">Connect to link this location to Google Business Profile.</p>
        @else
            @if($google->healthIsStale)
                <p class="cg-note-inline" style="margin:0 0 10px;color:var(--amber)" data-role="google-stale">Google data may be out of date. Reconnect or refresh it on the Google Business Profile page.</p>
            @endif
            @if($googleNap !== null)
                @php
                    $gExtra = array_values(array_diff($gDiffering, ['Name', 'Phone', 'Website']));
                @endphp
                <div class="cg-nap-line" data-role="google-nap">
                    @foreach(['name' => 'Name', 'phone' => 'Phone', 'website' => 'Website'] as $gField => $gFieldLabel)
                        @php
                            $gResult = $googleNap[$gField];
                            $gCls = $gResult === SeoNapFieldResult::Consistent ? 'ok' : ($gResult === SeoNapFieldResult::Mismatch ? 'diff' : 'none');
                            $gWord = match ($gResult) { SeoNapFieldResult::Consistent => 'matches', SeoNapFieldResult::Mismatch => 'differs', default => 'not compared' };
                        @endphp
                        <span class="cg-field cg-field--{{ $gCls }}" data-google-field="{{ $gField }}" data-result="{{ $gResult->value }}">{{ $gFieldLabel }} {{ $gWord }}</span>
                    @endforeach
                </div>
            @endif
            @if($google->health !== null || $googleNap !== null)
                <p class="cg-note-inline" style="margin:0 0 10px" data-role="google-verification">
                    @if($google->health !== null)<span data-role="google-health">{{ $google->health->label() }}@if($gAsOf !== null) <span data-role="google-health-as-of">as of {{ $gAsOf->format('M j, Y') }}</span>@endif</span>@endif
                    @if($googleNap !== null)@if($google->health !== null) &middot; @endif<span data-role="google-mismatch-count">{{ count($gDiffering) }} {{ count($gDiffering) === 1 ? 'detail differs' : 'details differ' }} from Google</span>@endif
                </p>
                @if($googleNap !== null && $gExtra !== [])
                    <p class="cg-note-inline" style="margin:0 0 10px" data-role="google-differing-fields">Differs: {{ implode(', ', $gExtra) }}.</p>
                @endif
            @endif
        @endif
        <div class="cg-acts">
            <a class="cg-btn {{ $googleState === SeoCitationLocationSection::GOOGLE_CONNECTED ? 'cg-ghost' : 'cg-primary' }} cg-sm" href="{{ $gbpUrl }}" data-role="google-action">{{ match ($googleState) { SeoCitationLocationSection::GOOGLE_CONNECTED => 'Manage', SeoCitationLocationSection::GOOGLE_CONNECTION_LOST => 'Reconnect', default => 'Connect' } }}</a>
            <span class="cg-hint">{{ $googleState === SeoCitationLocationSection::GOOGLE_NOT_LINKED ? 'Nothing is created until you connect.' : 'Details come from your Google connection.' }}</span>
        </div>
    </div>
</div>
