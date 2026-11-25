{{--
    Search-location picker. A typed string is NEVER sent to the provider: the
    visible box only searches the cached provider locations, and the form submits
    the chosen provider location CODE in the hidden field. Without a chosen
    suggestion the code stays empty and the server refuses.

    Markup follows the "choose tracking city" design export (see _rank-location-dialog).
--}}
<div class="kwd-field-wrap rank-location-field" data-role="rank-location-field" data-url="{{ $url }}" data-countries='@json(\App\Library\Seo\Rank\SeoRankLocationCatalog::COUNTRY_NAMES)'>
    <label class="kwd-field" for="{{ $fieldId }}">
        <svg class="kwd-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.500"></circle><path d="M16 16l4.500 4.500"></path></svg>
        <input type="text" autocomplete="off" maxlength="80" placeholder="Start typing a city, e.g. Toronto, Chicago or Vilnius" aria-label="Search location" data-role="rank-location-input" id="{{ $fieldId }}" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="{{ $fieldId }}-results">
    </label>
    <input type="hidden" name="search_location_code" value="" data-role="rank-location-code">
    <div class="kwd-results d-none" id="{{ $fieldId }}-results" role="listbox" aria-label="Matching cities" data-role="rank-location-results"></div>
    <div class="kwd-hintrow">
        <svg class="kwd-ic kwd-ic-sm" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.500"></circle><path d="M12 11v5M12 8h.01"></path></svg>
        {{-- Always-visible reason (a11y live region): why nothing can be chosen yet. --}}
        <span class="kwd-hint" data-role="rank-location-hint" role="status" aria-live="polite">{{ \App\Library\Seo\Rank\SeoRankLocationCatalog::supportedCountriesLabel() }}.</span>
        <span class="kwd-fromkw d-none" data-role="rank-location-keyword-hint">From your keyword: <button type="button" data-role="rank-location-keyword-pick"></button></span>
    </div>
</div>
