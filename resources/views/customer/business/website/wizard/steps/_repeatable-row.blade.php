@php
    $module = $step['target_module'];
    $isFaq = $module === 'faq';
    $isTestimonial = $module === 'knowledge_profile' && ($step['target_field'] ?? null) === 'testimonials';
    $isCustomSection = $module === 'custom_section';
    $isService = $module === 'business_service';
    $isBackdropWithCategories = $module === 'backdrop' && isset($step['categories']);
    $isLegacyBackdrop = $module === 'backdrop' && ! isset($step['categories']);
    $isLegacyPackage = $module === 'catalog_item';
    $f = fn (string $field) => $fieldBase . '[' . $index . '][' . $field . ']';
    $thumbUrl = ! empty($row['image_path']) ? asset($row['image_path']) : null;
@endphp
<div class="card card-body mb-2" data-row>
    <input type="hidden" name="{{ $f('key') }}" value="{{ $row['key'] ?? \Illuminate\Support\Str::random(12) }}">

    @if ($isFaq)
        <div class="mb-2">
            <label class="form-label">Question</label>
            <input type="text" name="{{ $f('question') }}" class="form-control" value="{{ $row['question'] ?? '' }}" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_FAQ_QUESTION }}">
        </div>
        <div class="mb-2">
            <label class="form-label">Answer</label>
            <textarea name="{{ $f('answer') }}" rows="2" class="form-control" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_FAQ_ANSWER }}">{{ $row['answer'] ?? '' }}</textarea>
        </div>
    @elseif ($isTestimonial)
        <div class="mb-2">
            <label class="form-label">Quote</label>
            <textarea name="{{ $f('quote') }}" rows="2" class="form-control" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_TESTIMONIAL_QUOTE }}">{{ $row['quote'] ?? '' }}</textarea>
        </div>
        <div class="row">
            <div class="col-md-6 mb-2">
                <label class="form-label">Customer name</label>
                <input type="text" name="{{ $f('author_name') }}" class="form-control" value="{{ $row['author_name'] ?? '' }}" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_TESTIMONIAL_AUTHOR }}">
            </div>
            <div class="col-md-6 mb-2">
                <label class="form-label">Title (optional)</label>
                <input type="text" name="{{ $f('author_title') }}" class="form-control" value="{{ $row['author_title'] ?? '' }}" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_TESTIMONIAL_AUTHOR }}">
            </div>
        </div>
    @else
        <div class="mb-2">
            <label class="form-label">Name</label>
            <input type="text" name="{{ $f('name') }}" class="form-control" value="{{ $row['name'] ?? '' }}" data-item-name maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_ITEM_NAME }}">
        </div>

        @if ($isBackdropWithCategories)
            <div class="mb-2">
                <label class="form-label">Category</label>
                <select name="{{ $f('category') }}" class="form-select" data-item-category>
                    <option value="">Choose a category&hellip;</option>
                    @foreach ($step['categories'] as $categoryValue => $categoryLabel)
                        <option value="{{ $categoryValue }}" @selected(($row['category'] ?? null) === $categoryValue)>{{ $categoryLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-2" data-image-picker
                 data-upload-url="{{ route('customer.workspaces.businesses.website.setup.backdrop-image.upload', [$workspaceUid, $businessUid]) }}"
                 data-remove-url="{{ route('customer.workspaces.businesses.website.setup.backdrop-image.remove', [$workspaceUid, $businessUid]) }}">
                <label class="form-label">Image</label>
                <input type="hidden" name="{{ $f('image_path') }}" value="{{ $row['image_path'] ?? '' }}" data-image-path>
                <div class="d-flex align-items-center gap-3">
                    <img src="{{ $thumbUrl }}" alt="" class="rounded {{ $thumbUrl ? '' : 'd-none' }}" style="width:96px;height:96px;object-fit:cover;" data-image-preview>
                    <div>
                        <label class="btn btn-outline-primary btn-sm mb-0" data-image-add>+ Add image
                            <input type="file" hidden accept="image/png,image/jpeg,image/webp" data-image-input>
                        </label>
                        <button type="button" class="btn btn-outline-secondary btn-sm d-none" data-image-replace>Replace</button>
                        <button type="button" class="btn btn-outline-danger btn-sm d-none" data-image-remove>Remove</button>
                        <div class="progress mt-2 d-none" style="height:4px;width:160px;"><div class="progress-bar" data-upload-progress></div></div>
                        <div class="text-danger small mt-1 d-none" data-upload-error></div>
                    </div>
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label">Image description (alt text)</label>
                <input type="text" name="{{ $f('alt_text') }}" class="form-control" value="{{ $row['alt_text'] ?? '' }}" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_BACKDROP_ALT }}" placeholder="Leave blank and we'll suggest one" data-image-alt>
            </div>
            <input type="hidden" name="{{ $f('availability') }}" value="1">
        @endif

        <div class="mb-2">
            <label class="form-label">Description @if ($isBackdropWithCategories)<span class="text-caption fw-normal">(optional)</span>@endif</label>
            <textarea name="{{ $f('description') }}" rows="2" class="form-control" data-item-description>{{ $row['description'] ?? '' }}</textarea>
            @if ($isService)
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-ai-describe
                        data-url="{{ route('customer.workspaces.businesses.website.setup.service-description', [$workspaceUid, $businessUid]) }}">Generate description with AI</button>
                <span class="text-caption ms-2 d-none" data-ai-status></span>
            @endif
        </div>
    @endif

    @if ($isLegacyPackage)
        {{-- v1 sessions only: a pinned v1 questionnaire keeps its inline package fields. --}}
        <div class="row">
            <div class="col-md-6 mb-2">
                <label class="form-label">Price (leave blank for "contact for pricing")</label>
                <input type="number" step="0.01" min="0" name="{{ $f('price') }}" class="form-control" value="{{ isset($row['price_minor']) ? number_format($row['price_minor'] / 100, 2, '.', '') : '' }}">
            </div>
            <div class="col-md-6 mb-2">
                <label class="form-label">Currency</label>
                <input type="text" maxlength="3" name="{{ $f('currency_code') }}" class="form-control text-uppercase" value="{{ $row['currency_code'] ?? 'USD' }}">
            </div>
        </div>
        <div class="mb-2">
            <label class="form-label">Included features (one per line)</label>
            <textarea name="{{ $f('features_text') }}" rows="2" class="form-control">{{ isset($row['features']) ? implode("\n", $row['features']) : '' }}</textarea>
        </div>
        <label class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="{{ $f('featured') }}" value="1" @checked(! empty($row['featured']))>
            <span class="form-check-label">Feature this package</span>
        </label>
    @endif

    @if ($isLegacyBackdrop)
        <label class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="{{ $f('availability') }}" value="1" @checked($row['availability'] ?? true)>
            <span class="form-check-label">Currently available</span>
        </label>
    @endif

    {{-- Only one custom section is supported (a deliberate limit). --}}
    @if ($isCustomSection)
        <div class="mb-2">
            <label class="form-label">Body / text</label>
            <textarea name="{{ $f('body') }}" rows="4" class="form-control">{{ $row['body'] ?? '' }}</textarea>
        </div>
        <div class="mb-2">
            <label class="form-label">Layout</label>
            <select name="{{ $f('layout') }}" class="form-select">
                @foreach (['stacked' => 'Stacked', 'image_left' => 'Image on the left', 'image_right' => 'Image on the right', 'grid' => 'Image grid'] as $layoutValue => $layoutLabel)
                    <option value="{{ $layoutValue }}" @selected(($row['layout'] ?? 'stacked') === $layoutValue)>{{ $layoutLabel }}</option>
                @endforeach
            </select>
        </div>
        @foreach (($row['images'] ?? []) as $imageUid)
            <input type="hidden" name="{{ $f('images') }}[]" value="{{ $imageUid }}" data-custom-image-field>
        @endforeach
    @endif

    @unless ($isCustomSection)
        <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="up" aria-label="Move up">&uarr;</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="down" aria-label="Move down">&darr;</button>
            <button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-remove-row>Remove</button>
        </div>
    @endunless
</div>
