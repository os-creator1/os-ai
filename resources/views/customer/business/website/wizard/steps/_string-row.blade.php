{{--
    One row of a one-per-row list. For a service area with `$detailsName`, the row also carries a collapsed, optional
    "local details" panel: venues the owner really worked at, how serving the place works, and event photos from their
    own gallery. The greyed text is an EXAMPLE only (a placeholder — never saved or published); what the owner types is
    serialised into the row's hidden JSON input, which travels with the row when it is moved or removed.
--}}
@php
    $detail = is_array($detail ?? null) ? $detail : [];
    $venueText = implode("\n", (array) ($detail['venues'] ?? []));
    $logisticsText = (string) ($detail['logistics'] ?? '');
    $chosenPhotos = (array) ($detail['photos'] ?? []);
    $hasDetail = $venueText !== '' || $logisticsText !== '' || $chosenPhotos !== [];
    $detailsPayload = $hasDetail ? json_encode(['venues' => $venueText, 'logistics' => $logisticsText, 'photos' => array_values($chosenPhotos)]) : '';
@endphp
<div class="d-flex flex-wrap gap-2 mb-2 align-items-center" data-row>
    <input type="text" name="{{ $fieldName }}[]" class="form-control flex-grow-1" style="min-width:0;" value="{{ $value }}" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_LIST_ITEM_LENGTH }}" placeholder="{{ $placeholder ?? '' }}" aria-label="{{ ($placeholder ?? '') !== '' ? 'Location name' : 'Entry' }}">
    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" data-move-row="up" aria-label="Move up">&uarr;</button>
    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" data-move-row="down" aria-label="Move down">&darr;</button>
    <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" data-remove-row aria-label="Remove"><span class="d-none d-sm-inline">Remove</span><span class="d-sm-none" aria-hidden="true">&times;</span></button>
    @if (! empty($detailsName))
        <input type="hidden" name="{{ $detailsName }}[]" value="{{ $detailsPayload }}" data-area-details-json>
        <details class="w-100 border rounded px-3 py-2" data-area-detail-panel @if ($hasDetail) open @endif>
            <summary class="text-caption" style="cursor:pointer;">
                Add local details (optional)
                <span class="badge bg-success ms-1 {{ $hasDetail ? '' : 'd-none' }}" data-area-detail-badge>Details added</span>
            </summary>
            <p class="text-caption mt-2 mb-2">Only add what is true. These details let this place get its own page; anything you leave blank is simply not mentioned. The grey text is an example, not something we will publish.</p>
            <label class="form-label small mb-1">Venues you have actually worked at here <span class="text-caption fw-normal">(one per line)</span></label>
            <textarea class="form-control form-control-sm mb-2" rows="2" maxlength="1000" data-area-field="venues" placeholder="{{ \App\Library\Website\Setup\ServiceAreaDetails::EXAMPLES['venues'] }}">{{ $venueText }}</textarea>
            <label class="form-label small mb-1">Travel, setup or service details for this area</label>
            <textarea class="form-control form-control-sm mb-2" rows="2" maxlength="600" data-area-field="logistics" placeholder="{{ \App\Library\Website\Setup\ServiceAreaDetails::EXAMPLES['logistics'] }}">{{ $logisticsText }}</textarea>
            @if (! empty($galleryPhotos))
                @php($photoMax = \App\Library\Website\LocalAreaUsefulness::MAX_PHOTOS)
                <div class="small mb-1">Event photos taken here <span class="text-caption fw-normal">(from your uploaded gallery)</span>
                    <span class="ms-2 fw-bold" data-area-photo-count role="status" data-max="{{ $photoMax }}">{{ count($chosenPhotos) }} of {{ $photoMax }} photos selected</span></div>
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($galleryPhotos as $photo)
                        <label class="d-block" style="cursor:pointer;">
                            <input type="checkbox" class="form-check-input me-1" value="{{ $photo['uid'] }}" data-area-photo @checked(in_array($photo['uid'], $chosenPhotos, true)) @disabled(! in_array($photo['uid'], $chosenPhotos, true) && count($chosenPhotos) >= $photoMax)>
                            <img src="{{ $photo['url'] }}" alt="{{ $photo['alt'] }}" style="width:64px;height:64px;object-fit:cover;" class="rounded align-middle">
                        </label>
                    @endforeach
                </div>
                @if (($galleryTotal ?? 0) > count($galleryPhotos))
                    <p class="text-caption mt-1 mb-0" data-area-photo-note>Showing {{ count($galleryPhotos) }} of your {{ $galleryTotal }} gallery photos (the first ones, plus any you already picked).</p>
                @endif
            @else
                <p class="text-caption mb-0" data-area-photo-hint>No photos yet? You&rsquo;ll upload your event photos in the Gallery step later. Afterwards, choose <strong>Edit</strong> next to Service areas on the final Review screen to come back here and pick the ones taken in this place.</p>
            @endif
        </details>
    @endif
</div>
