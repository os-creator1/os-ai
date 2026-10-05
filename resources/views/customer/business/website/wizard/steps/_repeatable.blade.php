{{--
    A repeatable group: rows are added/removed/re-ordered in place. It
    starts with ONE row (never a block of blank cards); blank rows are
    dropped when the screen is saved.
--}}
@php
    $isCustomSectionGroup = ($step['target_module'] ?? null) === 'custom_section';
    $rows = array_values($rows);
    $rows = $isCustomSectionGroup ? [$rows[0] ?? []] : ($rows !== [] ? $rows : [[]]);
    $addNoun = match (true) {
        ($step['target_module'] ?? null) === 'business_service' => 'service',
        ($step['target_module'] ?? null) === 'backdrop' => 'backdrop',
        ($step['target_module'] ?? null) === 'faq' => 'question',
        ($step['target_module'] ?? null) === 'knowledge_profile' => 'review',
        default => 'entry',
    };
    $faqSuggestions = (($step['target_module'] ?? null) === 'faq')
        ? \App\Library\Website\Setup\NicheFaqSuggestions::for(app(\App\Library\Website\Setup\QuestionnaireResolver::class)->nicheKeyFor($business))
        : [];
@endphp
@if ($faqSuggestions !== [])
    <div class="mb-2" data-testid="faq-suggestions">
        <div class="text-caption mb-1">Common questions for your kind of business — tap one, then write your own answer:</div>
        <div class="d-flex flex-wrap gap-1">
            @foreach ($faqSuggestions as $suggestion)
                <button type="button" class="btn btn-sm btn-outline-secondary" data-faq-suggestion="{{ $suggestion }}">{{ $suggestion }}</button>
            @endforeach
        </div>
    </div>
@endif
<div data-repeatable @if($isCustomSectionGroup) data-max-rows="1" @endif>
    <div data-repeatable-rows>
        @foreach ($rows as $i => $row)
            @include('customer.business.website.wizard.steps._repeatable-row', ['index' => $i, 'row' => $row])
        @endforeach
    </div>
    <template data-row-template>
        @include('customer.business.website.wizard.steps._repeatable-row', ['index' => '__INDEX__', 'row' => []])
    </template>
    @unless ($isCustomSectionGroup)
        <button type="button" class="btn btn-link px-0" data-add-row>+ Add another {{ $addNoun }}</button>
    @endunless
</div>

@if ($isCustomSectionGroup)
    @php
        $customSectionImages = \App\Models\WebsiteAsset::where('website_id', $website->id)->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::CustomSection->value)->orderBy('sort_order')->get();
    @endphp
    <div class="mt-3" data-custom-images
         data-upload-url="{{ route('customer.workspaces.businesses.website.setup.custom-section.upload', [$workspaceUid, $businessUid]) }}"
         data-remove-url="{{ route('customer.workspaces.businesses.website.setup.custom-section.remove', [$workspaceUid, $businessUid, '__UID__']) }}"
         data-field-name="{{ $fieldBase }}[0][images][]"
         data-max="{{ \App\Library\Website\Gallery\WebsiteGalleryManager::MAX_CUSTOM_SECTION_ASSETS }}">
        <div class="d-flex flex-wrap gap-2 mb-2" data-custom-images-list>
            @foreach ($customSectionImages as $image)
                <div class="text-center" data-custom-image data-uid="{{ $image->uid }}">
                    <img src="{{ asset($image->path) }}" alt="{{ $image->alt_text }}" style="width:80px;height:80px;object-fit:cover;" class="rounded d-block mb-1">
                    <button type="button" class="btn btn-sm btn-outline-danger" data-custom-image-remove>Remove</button>
                </div>
            @endforeach
        </div>
        <label class="btn btn-outline-primary btn-sm mb-0">+ Add image
            <input type="file" hidden accept="image/png,image/jpeg,image/webp" data-custom-image-input>
        </label>
        <div class="progress mt-2 d-none" style="height:4px"><div class="progress-bar" data-upload-progress></div></div>
        <div class="text-danger small d-none" data-upload-error></div>
    </div>
@endif
