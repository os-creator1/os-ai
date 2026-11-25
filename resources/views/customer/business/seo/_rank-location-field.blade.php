{{--
    Search-location picker (combobox). A typed string is NEVER sent to the provider: the
    visible box only searches the cached provider locations, and the form submits
    the chosen provider location CODE in the hidden field. Without a chosen
    suggestion the code stays empty and the server refuses.

    Markup follows the "choose tracking city" design: a focused search field, the
    suggestion list directly beneath it (city left, region + country right, one active
    row), then one status/hint line. Styles live with the dialog (.rl-*).
--}}
<div class="rl-picker rank-location-field" data-role="rank-location-field" data-url="{{ $url }}">
    <label class="rl-field" for="{{ $fieldId }}">
        <svg class="rl-ic rl-ic-muted" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.500"></circle><path d="M16 16l4.500 4.500"></path></svg>
        <input class="rl-input" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="80"
               placeholder="e.g. Toronto, Chicago or Vilnius" aria-label="Search location" data-role="rank-location-input" id="{{ $fieldId }}"
               role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="{{ $fieldId }}-list">
    </label>
    <input type="hidden" name="search_location_code" value="" data-role="rank-location-code">
    <div class="rl-list d-none" role="listbox" aria-label="Matching cities" id="{{ $fieldId }}-list" data-role="rank-location-results"></div>
    {{-- Always-visible reason (a11y live region): why nothing can be chosen yet; the keyword's own city is offered beside it. --}}
    <div class="rl-hint">
        <svg class="rl-ic rl-ic-sm" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.500"></circle><path d="M12 11v5M12 8h.01"></path></svg>
        <span class="rl-hint-text" data-role="rank-location-hint" role="status" aria-live="polite">{{ \App\Library\Seo\Rank\SeoRankLocationCatalog::supportedCountriesShortLabel() }}.</span>
        <span class="rl-hint-kw d-none" data-role="rank-location-keyword-hint">From your keyword: <button type="button" class="rl-link" data-role="rank-location-keyword-pick"></button></span>
    </div>
</div>
