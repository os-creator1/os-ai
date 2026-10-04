{{--
    Search-location picker. A typed string is NEVER sent to the provider: the
    visible box only searches the cached provider locations, and the form submits
    the chosen provider location CODE in the hidden field. Without a chosen
    suggestion the code stays empty and the server refuses.
--}}
<div class="position-relative rank-location-field" data-role="rank-location-field" data-url="{{ $url }}">
    <input class="form-control" type="text" autocomplete="off" maxlength="80" placeholder="Start typing a city, e.g. Chicago" aria-label="Search location" data-role="rank-location-input" id="{{ $fieldId }}">
    <input type="hidden" name="search_location_code" value="" data-role="rank-location-code">
    <div class="list-group position-absolute w-100 shadow-sm d-none" style="z-index: 20; max-height: 220px; overflow-y: auto;" data-role="rank-location-results"></div>
    <p class="text-caption mb-0 mt-50 d-none text-danger" data-role="rank-location-hint">Choose a location from the list.</p>
</div>
