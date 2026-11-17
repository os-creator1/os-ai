{{--
    "Suggested for you": ideas from the business type's keyword strategy, filled in
    ONLY with the Business's own services and cities. Nothing is saved until "Track"
    (or "Add" when rank tracking is not available) is pressed — an ordinary keyword
    create POST that also starts rank tracking when the Business can track.
    Escaped output only.

    @param array<int, \App\Library\Seo\SeoKeywordSuggestion> $suggestions
    @param bool $canTrackRank
--}}
<section class="kw-card" style="overflow: hidden" data-section="suggested-keywords">
    <div class="kw-card-head"><b>Suggested for you</b><span class="kw-mut">From your services and location</span></div>
    @foreach($suggestions as $suggestion)
        <div class="kw-sugg" data-role="suggested-keyword" data-phrase="{{ $suggestion->phrase }}">
            <div>
                <span class="kw-name" data-role="suggested-phrase">{{ $suggestion->phrase }}</span>
                <span class="kw-mut">{{ $suggestion->locationName ?? 'Whole business' }}</span>
            </div>
            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.store', [$workspaceUid, $businessUid]) }}" data-role="suggestion-form" @if($suggestion->locationUid !== null) data-location-uid="{{ $suggestion->locationUid }}" @endif>
                @csrf
                <input type="hidden" name="phrase" value="{{ $suggestion->phrase }}">
                @if($suggestion->locationUid !== null)
                    <input type="hidden" name="location_uid" value="{{ $suggestion->locationUid }}">
                @endif
                @if($canTrackRank)
                    <input type="hidden" name="track_rank" value="1">
                    <input type="hidden" name="search_location_code" value="" data-role="suggestion-location-code">
                @endif
                <button class="kw-btn is-ghost" type="submit" data-role="suggestion-add"><svg class="kw-ic" viewBox="0 0 24 24" style="width:14px;height:14px" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>{{ $canTrackRank ? 'Track' : 'Add' }}</button>
            </form>
        </div>
    @endforeach
</section>
