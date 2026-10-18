{{--
    One card of a booth-service / event-type list (a step with `row_fields`).
    Which inputs show is the step definition's choice, never hardcoded here.
    Photos are stored the moment they are chosen (the Business image store) and
    only their paths travel with the answer; the canonical service owns the
    picture once the setup is applied, attached by the service's own id.
--}}
@php
    $fields = $step['row_fields'] ?? ['name', 'description'];
    $has = fn (string $field) => in_array($field, $fields, true);
    $f = fn (string $field) => $fieldBase . '[' . $index . '][' . $field . ']';
    $maxExtra = \App\Library\Business\BusinessServiceImageManager::MAX_IMAGES - 1;
    $extras = array_values($row['extra_image_paths'] ?? []);
    $uploadUrl = route('customer.workspaces.businesses.website.setup.backdrop-image.upload', [$workspaceUid, $businessUid]);
    $removeUrl = route('customer.workspaces.businesses.website.setup.backdrop-image.remove', [$workspaceUid, $businessUid]);
    $visible = array_key_exists('visible', $row) ? (bool) $row['visible'] : true;
    $currencyDefault = strtoupper((string) ($business->currency_code ?: 'USD'));
@endphp
<div class="card card-body mb-2" data-row data-service-card>
    <input type="hidden" name="{{ $f('key') }}" value="{{ $row['key'] ?? \Illuminate\Support\Str::random(12) }}">
    <input type="hidden" name="{{ $f('preset') }}" value="{{ $row['preset'] ?? '' }}" data-preset-value>

    <div class="mb-2">
        <label class="form-label">Name</label>
        <input type="text" name="{{ $f('name') }}" class="form-control" value="{{ $row['name'] ?? '' }}" data-item-name maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_ITEM_NAME }}">
    </div>

    @if ($has('description'))
        <div class="mb-2">
            <label class="form-label">Description <span class="text-caption fw-normal">(we start you off — edit it freely)</span></label>
            <textarea name="{{ $f('description') }}" rows="3" class="form-control" data-item-description maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_ITEM_DESCRIPTION }}">{{ $row['description'] ?? '' }}</textarea>
        </div>
    @endif

    @if ($has('cover'))
        <div class="mb-2" data-image-picker data-upload-url="{{ $uploadUrl }}" data-remove-url="{{ $removeUrl }}">
            <label class="form-label">Cover photo <span class="text-caption fw-normal">— a real photo of this booth</span></label>
            <input type="hidden" name="{{ $f('image_path') }}" value="{{ $row['image_path'] ?? '' }}" data-image-path>
            <div class="d-flex align-items-center gap-3">
                <img src="{{ ! empty($row['image_path']) ? asset($row['image_path']) : '' }}" alt="" class="rounded {{ ! empty($row['image_path']) ? '' : 'd-none' }}" style="width:96px;height:96px;object-fit:cover;" data-image-preview>
                <div>
                    <label class="btn btn-outline-primary btn-sm mb-0" data-image-add>+ Add photo
                        <input type="file" hidden accept="image/png,image/jpeg,image/webp" data-image-input>
                    </label>
                    <button type="button" class="btn btn-outline-secondary btn-sm d-none" data-image-replace>Replace</button>
                    <button type="button" class="btn btn-outline-danger btn-sm d-none" data-image-remove>Remove</button>
                    <div class="progress mt-2 d-none" style="height:4px;width:160px;"><div class="progress-bar" data-upload-progress></div></div>
                    <div class="text-danger small mt-1 d-none" data-upload-error></div>
                </div>
            </div>
        </div>
    @endif

    @if ($has('extra_images'))
        <details class="mb-2" @if ($extras !== []) open @endif>
            <summary class="text-label">More photos <span class="text-caption fw-normal">(optional)</span></summary>
            <div class="d-flex flex-wrap gap-3 mt-2">
                @for ($e = 0; $e < min(3, $maxExtra); $e++)
                    @php $extraPath = $extras[$e] ?? ''; @endphp
                    <div data-image-picker data-upload-url="{{ $uploadUrl }}" data-remove-url="{{ $removeUrl }}">
                        <input type="hidden" name="{{ $f('extra_image_paths') }}[]" value="{{ $extraPath }}" data-image-path>
                        <img src="{{ $extraPath !== '' ? asset($extraPath) : '' }}" alt="" class="rounded d-block mb-1 {{ $extraPath !== '' ? '' : 'd-none' }}" style="width:72px;height:72px;object-fit:cover;" data-image-preview>
                        <label class="btn btn-outline-primary btn-sm mb-0" data-image-add>+ Photo
                            <input type="file" hidden accept="image/png,image/jpeg,image/webp" data-image-input>
                        </label>
                        <button type="button" class="btn btn-outline-secondary btn-sm d-none" data-image-replace>Replace</button>
                        <button type="button" class="btn btn-outline-danger btn-sm d-none" data-image-remove>Remove</button>
                        <div class="progress mt-2 d-none" style="height:4px;width:110px;"><div class="progress-bar" data-upload-progress></div></div>
                        <div class="text-danger small mt-1 d-none" data-upload-error></div>
                    </div>
                @endfor
            </div>
        </details>
    @endif

    @if ($has('features'))
        @include('customer.business.website.wizard.steps._feature-list', ['features' => $row['features'] ?? [], 'nameTemplate' => $fieldBase . '[{i}][features][]', 'index' => $index])
    @endif

    @if ($has('price'))
        <div class="row">
            <div class="col-md-6 mb-2">
                <label class="form-label">{{ ($step['target_module'] ?? '') === 'catalog_item' ? 'Price' : 'Starting price' }} <span class="text-caption fw-normal">(optional — leave blank if you quote each event)</span></label>
                <input type="text" inputmode="decimal" name="{{ $f('price') }}" class="form-control" value="{{ isset($row['starting_price']) && $row['starting_price'] !== null ? number_format((float) $row['starting_price'], 2, '.', '') : (isset($row['price_minor']) && $row['price_minor'] !== null ? number_format($row['price_minor'] / 100, 2, '.', '') : '') }}">
            </div>
            <div class="col-md-6 mb-2">
                <label class="form-label">Currency</label>
                <input type="text" maxlength="3" name="{{ $f('currency_code') }}" class="form-control text-uppercase" value="{{ $row['currency_code'] ?? $currencyDefault }}">
            </div>
        </div>
    @endif

    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
        @if ($has('visible'))
            <label class="form-check mb-0 me-auto">
                <input class="form-check-input" type="checkbox" name="{{ $f('visible') }}" value="1" @checked($visible)>
                <span class="form-check-label">Show on my website</span>
            </label>
        @else
            <span class="me-auto"></span>
        @endif
        <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="up" aria-label="Move up">&uarr;</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="down" aria-label="Move down">&darr;</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-remove-row>Remove</button>
    </div>
</div>
