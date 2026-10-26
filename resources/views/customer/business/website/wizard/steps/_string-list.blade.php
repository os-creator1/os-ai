{{--
    A one-per-row list of short strings (service areas). Rows are added,
    removed and re-ordered in place; the entered order is the owner's
    priority and is preserved. Nothing is split apart by a delimiter.

    For a step with `suggest`, the rows may start out as grounded nearby-place
    SUGGESTIONS (only when the owner has no saved list and the Business has no
    saved service areas). They are editable rows like any other — and are said
    to be suggestions, not verified service claims.
--}}
@php
    $noun = ($step['target_module'] ?? null) === 'business_location' ? 'location' : 'entry';
    $placeholder = $noun === 'location' ? 'City or area, e.g. Naperville, IL' : '';
    $entries = array_values($entries) !== [] ? array_values($entries) : [''];
    $area = $areaState ?? null;

    // Optional per-area local details (the step's `area_details` flag): saved beside the list, keyed by the area's slug.
    $detailsName = ! empty($areaDetailsName) ? $areaDetailsName : null;
    $detailsBySlug = is_array($areaDetailsSaved ?? null) ? $areaDetailsSaved : [];
    $galleryPhotos = $detailsName ? ($areaPhotos ?? []) : [];
    $rowFor = fn (string $entry) => [
        'value' => $entry,
        'detailsName' => $detailsName,
        'detail' => $detailsBySlug[\Illuminate\Support\Str::slug($entry)] ?? [],
        'galleryPhotos' => $galleryPhotos,
    ];
@endphp
@if ($area)
    <div data-area-list
         data-suggest-url="{{ $area['url'] }}"
         @if ($area['base']) data-base-city="{{ $area['base']['city'] }}" data-base-region="{{ $area['base']['region'] }}" data-base-country="{{ $area['base']['country_code'] }}" @endif>
        @if ($area['source'] === 'suggested')
            <div class="border rounded p-3 mb-3 text-body" data-area-note="suggested">
                We suggested these communities near <strong>{{ $area['base_label'] }}</strong> to get you started. They&rsquo;re suggestions, not verified &mdash; edit, remove or add any you really serve.
            </div>
        @elseif ($area['source'] === 'canonical')
            <p class="text-caption" data-area-note="canonical">These are the service areas already saved for your business. Change anything you like.</p>
        @elseif ($area['source'] === 'none' && $area['base'] === null)
            <p class="text-caption" data-area-note="no-base">Add the cities and areas you serve, one per row.</p>
        @elseif ($area['source'] === 'none')
            <p class="text-caption" data-area-note="no-suggestions">We couldn&rsquo;t find nearby communities for {{ $area['base_label'] }}. Add the areas you serve, one per row.</p>
        @endif
        @if ($area['base_changed'])
            <div class="alert alert-warning py-2" data-area-note="base-changed">
                Your base location is now <strong>{{ $area['base_label'] }}</strong>. Your service areas below are unchanged.
                <button type="button" class="btn btn-link p-0 align-baseline" data-area-refresh>Replace them with fresh suggestions</button>
            </div>
        @endif
    </div>
@endif
<div data-repeatable>
    <div data-repeatable-rows>
        @foreach ($entries as $entry)
            @include('customer.business.website.wizard.steps._string-row', $rowFor($entry))
        @endforeach
    </div>
    <template data-row-template>
        @include('customer.business.website.wizard.steps._string-row', $rowFor(''))
    </template>
    <button type="button" class="btn btn-link px-0" data-add-row>+ Add another {{ $noun }}</button>
    @if ($area && $area['base'])
        <button type="button" class="btn btn-link px-0 ms-3" data-area-suggest-more>Suggest more nearby areas</button>
        <span class="text-caption ms-2 d-none" data-area-status role="status"></span>
    @endif
</div>
