@extends('layouts/contentLayoutMaster')

@section('title', $step['prompt'])

@section('content')
    @php
        $rows = is_array($answer) ? $answer : [];
        // A fixed number of blank rows for a repeatable question — the
        // simplest JS-free way to let an owner add several structured
        // entries on one screen; empty rows are dropped on submit
        // (WebsiteWizardController::valueFromRequest()). A richer
        // dynamic "add another" interaction is a natural follow-up, not
        // required to prove the underlying pipeline end to end.
        // Independent-review correction round 3 — v1 supports exactly ONE
        // custom section (the brief's own wording is singular, and
        // generation only ever reads the first entry); rendering more
        // than one row here would silently invite content that is never
        // used. Every other repeatable question keeps the existing
        // "always one blank spare row" behavior.
        $isCustomSectionGroup = ($step['target_module'] ?? null) === 'custom_section';
        $blankRowCount = $isCustomSectionGroup ? 1 : max(3, count($rows) + 1);
        $answerFormId = 'answer-form-' . $step['key'];
    @endphp

    <div class="row justify-content-center">
        <div class="col-lg-7">
            @include('customer.business.website.wizard._progress', ['progress' => $progress, 'backStepKey' => $step['key'], 'currentStepKey' => $step['key']])

            <x-flash-alert class="mb-3" />

            <h4 class="mb-1">{{ $step['prompt'] }}</h4>
            @if ($step['help_text'])
                <p class="text-caption mb-3">{{ $step['help_text'] }}</p>
            @endif

            {{--
                Independent-review correction round 2 — every distinct
                submission target is its own SIBLING <form>, never nested
                inside another. This is the one answer/autosave form; the
                gallery management controls and the custom-section image/
                improve controls below are separate forms of their own
                (each with $answerFormId's own id where a button needs to
                submit ITS OWN sibling form's data to a different action —
                see the "Improve with AI" button, which uses `form=` to
                submit THIS form's own currently-typed fields without
                being a descendant of it).
            --}}
            <form id="{{ $answerFormId }}" method="POST" action="{{ route('customer.workspaces.businesses.website.setup.autosave', [$workspaceUid, $businessUid, $step['key']]) }}">
                @csrf
                <input type="hidden" name="answers_revision" value="{{ $response->answers_revision }}">

                @switch($step['input_type'])
                    @case('text')
                    @case('tel')
                    @case('email')
                        <input type="{{ $step['input_type'] }}" name="value" class="form-control mb-3" value="{{ old('value', is_string($answer) ? $answer : '') }}" @if($step['required']) required @endif>
                        @break

                    @case('textarea')
                        <textarea name="value" rows="4" class="form-control mb-3" @if($step['required']) required @endif>{{ old('value', is_string($answer) ? $answer : '') }}</textarea>
                        @break

                    @case('boolean')
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="value" id="{{ $step['key'] }}-yes" value="1" @checked($answer === true)>
                                <label class="form-check-label" for="{{ $step['key'] }}-yes">Yes</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="value" id="{{ $step['key'] }}-no" value="0" @checked($answer === false)>
                                <label class="form-check-label" for="{{ $step['key'] }}-no">No</label>
                            </div>
                        </div>
                        @break

                    @case('select')
                        <select name="value" class="form-select mb-3" @if($step['required']) required @endif>
                            <option value="">Choose one&hellip;</option>
                            @foreach ($step['options'] ?? [] as $optionValue => $optionLabel)
                                <option value="{{ $optionValue }}" @selected($answer === $optionValue)>{{ $optionLabel }}</option>
                            @endforeach
                        </select>
                        @break

                    @case('multi_select')
                        <div class="mb-3">
                            @foreach ($step['options'] ?? [] as $optionValue => $optionLabel)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="value[]" id="{{ $step['key'] }}-{{ $optionValue }}" value="{{ $optionValue }}" @checked(is_array($answer) && in_array($optionValue, $answer, true))>
                                    <label class="form-check-label" for="{{ $step['key'] }}-{{ $optionValue }}">{{ $optionLabel }}</label>
                                </div>
                            @endforeach
                        </div>
                        @break

                    @case('repeatable_group')
                        @php
                            $isFaqGroup = $step['target_module'] === 'faq';
                            $isTestimonialGroup = $step['target_module'] === 'knowledge_profile' && ($step['target_field'] ?? null) === 'testimonials';
                            $isCustomSectionGroup = $step['target_module'] === 'custom_section';
                        @endphp
                        @for ($i = 0; $i < $blankRowCount; $i++)
                            @php $row = $rows[$i] ?? []; @endphp
                            <x-card class="mb-2">
                                <input type="hidden" name="items[{{ $i }}][key]" value="{{ $row['key'] ?? \Illuminate\Support\Str::random(12) }}">

                                @if ($isFaqGroup)
                                    <div class="mb-2">
                                        <label class="form-label">Question</label>
                                        <input type="text" name="items[{{ $i }}][question]" class="form-control" value="{{ $row['question'] ?? '' }}" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_FAQ_QUESTION }}">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Answer</label>
                                        <textarea name="items[{{ $i }}][answer]" rows="2" class="form-control" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_FAQ_ANSWER }}">{{ $row['answer'] ?? '' }}</textarea>
                                    </div>
                                @elseif ($isTestimonialGroup)
                                    <div class="mb-2">
                                        <label class="form-label">Quote</label>
                                        <textarea name="items[{{ $i }}][quote]" rows="2" class="form-control" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_TESTIMONIAL_QUOTE }}">{{ $row['quote'] ?? '' }}</textarea>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Customer name</label>
                                            <input type="text" name="items[{{ $i }}][author_name]" class="form-control" value="{{ $row['author_name'] ?? '' }}" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_TESTIMONIAL_AUTHOR }}">
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Title (optional)</label>
                                            <input type="text" name="items[{{ $i }}][author_title]" class="form-control" value="{{ $row['author_title'] ?? '' }}" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_TESTIMONIAL_AUTHOR }}">
                                        </div>
                                    </div>
                                @else
                                    <div class="mb-2">
                                        <label class="form-label">Name</label>
                                        <input type="text" name="items[{{ $i }}][name]" class="form-control" value="{{ $row['name'] ?? '' }}">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Description</label>
                                        <textarea name="items[{{ $i }}][description]" rows="2" class="form-control">{{ $row['description'] ?? '' }}</textarea>
                                    </div>
                                @endif

                                @if ($step['target_module'] === 'catalog_item')
                                    <div class="row">
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Price (leave blank for "contact for pricing")</label>
                                            <input type="number" step="0.01" min="0" name="items[{{ $i }}][price]" class="form-control" value="{{ isset($row['price_minor']) ? number_format($row['price_minor'] / 100, 2, '.', '') : '' }}">
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Currency</label>
                                            <input type="text" maxlength="3" name="items[{{ $i }}][currency_code]" class="form-control text-uppercase" value="{{ $row['currency_code'] ?? 'USD' }}">
                                        </div>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Included features (one per line)</label>
                                        <textarea name="items[{{ $i }}][features_text]" rows="2" class="form-control">{{ isset($row['features']) ? implode("\n", $row['features']) : '' }}</textarea>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="items[{{ $i }}][featured]" id="featured-{{ $i }}" value="1" @checked(! empty($row['featured']))>
                                        <label class="form-check-label" for="featured-{{ $i }}">Feature this package</label>
                                    </div>
                                @endif

                                @if ($step['target_module'] === 'backdrop')
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="items[{{ $i }}][availability]" id="availability-{{ $i }}" value="1" @checked($row['availability'] ?? true)>
                                        <label class="form-check-label" for="availability-{{ $i }}">Currently available</label>
                                    </div>
                                @endif

                                {{-- Only one custom section is supported this pass (deliberate v1 limit) — only the first row's fields are shown/used. --}}
                                @if ($isCustomSectionGroup && $i === 0)
                                    <div class="mb-2">
                                        <label class="form-label">Body / text</label>
                                        <textarea name="items[{{ $i }}][body]" rows="4" class="form-control">{{ $row['body'] ?? '' }}</textarea>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Layout</label>
                                        <select name="items[{{ $i }}][layout]" class="form-select">
                                            @foreach (['stacked' => 'Stacked', 'image_left' => 'Image on the left', 'image_right' => 'Image on the right', 'grid' => 'Image grid'] as $layoutValue => $layoutLabel)
                                                <option value="{{ $layoutValue }}" @selected(($row['layout'] ?? 'stacked') === $layoutValue)>{{ $layoutLabel }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @foreach (($row['images'] ?? []) as $imageUid)
                                        <input type="hidden" name="items[{{ $i }}][images][]" value="{{ $imageUid }}">
                                    @endforeach
                                @endif
                            </x-card>
                        @endfor
                        @break

                    @case('photo_upload')
                        <input type="hidden" name="value" value="{{ $website->assets()->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::Gallery->value)->count() }}">
                        @break

                    @default
                        <input type="text" name="value" class="form-control mb-3" value="{{ old('value', is_string($answer) ? $answer : '') }}">
                @endswitch

                <div class="d-flex gap-2 mt-3">
                    <x-button type="submit" variant="primary">Continue</x-button>
                    @if (! $step['required'])
                        <x-button type="submit" name="skip" value="1" variant="outline">Skip</x-button>
                    @endif
                    @if ($step['target_module'] === 'custom_section')
                        {{--
                            Independent-review correction round 2 — a
                            SIBLING button, associated with the answer
                            form above by id via `form=` (standard HTML5;
                            this is NOT nesting), submitting that form's
                            OWN currently-typed fields to the improve
                            action instead of the autosave action. This is
                            what makes "Improve with AI" act on whatever
                            the owner has typed right now, never on a
                            stale persisted value.
                        --}}
                        <button type="submit" form="{{ $answerFormId }}" formaction="{{ route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspaceUid, $businessUid]) }}" class="btn btn-outline-secondary">Improve with AI</button>
                    @endif
                </div>
            </form>

            @if ($step['input_type'] === 'photo_upload')
                @php $galleryAssets = $website->assets()->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::Gallery->value)->orderBy('sort_order')->get(); @endphp

                <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.gallery.upload', [$workspaceUid, $businessUid]) }}" enctype="multipart/form-data" class="mt-3 mb-3">
                    @csrf
                    <label class="form-label">Upload photos ({{ $galleryAssets->count() }} of {{ \App\Library\Website\Gallery\WebsiteGalleryManager::MAX_GALLERY_ASSETS }})</label>
                    <input type="file" name="photos[]" multiple accept="image/*" class="form-control mb-2">
                    <x-button type="submit" variant="outline">Upload</x-button>
                </form>

                @foreach ($galleryAssets as $asset)
                    <x-card class="mb-2">
                        <div class="d-flex gap-3 align-items-start">
                            <img src="{{ asset($asset->path) }}" alt="{{ $asset->alt_text }}" style="width:96px;height:96px;object-fit:cover;" class="rounded">
                            <div class="flex-grow-1">
                                <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.gallery.update', [$workspaceUid, $businessUid, $asset->uid]) }}" class="mb-2">
                                    @csrf
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <input type="text" name="title" class="form-control form-control-sm" placeholder="Title" value="{{ $asset->title }}">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="text" name="category_tag" class="form-control form-control-sm" placeholder="Category" value="{{ $asset->category_tag }}">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="text" name="alt_text" class="form-control form-control-sm" placeholder="Alt text (optional)" value="{{ $asset->alt_text_is_custom ? $asset->alt_text : '' }}">
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Save</button>
                                        </div>
                                    </div>
                                </form>
                                <div class="d-flex gap-1">
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.gallery.update', [$workspaceUid, $businessUid, $asset->uid]) }}">
                                        @csrf
                                        <input type="hidden" name="is_cover" value="1">
                                        <button type="submit" class="btn btn-sm {{ $asset->is_cover ? 'btn-primary' : 'btn-outline-primary' }}">{{ $asset->is_cover ? 'Cover photo' : 'Make cover' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.gallery.move', [$workspaceUid, $businessUid, $asset->uid]) }}">
                                        @csrf
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">&uarr;</button>
                                    </form>
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.gallery.move', [$workspaceUid, $businessUid, $asset->uid]) }}">
                                        @csrf
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">&darr;</button>
                                    </form>
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.gallery.remove', [$workspaceUid, $businessUid, $asset->uid]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </x-card>
                @endforeach
            @endif

            @if ($step['target_module'] === 'custom_section')
                @php $customSectionImages = \App\Models\WebsiteAsset::where('website_id', $website->id)->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::CustomSection->value)->orderBy('sort_order')->get(); @endphp

                <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.custom-section.upload', [$workspaceUid, $businessUid]) }}" enctype="multipart/form-data" class="mt-3 mb-3">
                    @csrf
                    <label class="form-label">Add an image ({{ $customSectionImages->count() }} of {{ \App\Library\Website\Gallery\WebsiteGalleryManager::MAX_CUSTOM_SECTION_ASSETS }})</label>
                    <input type="file" name="photo" accept="image/*" class="form-control mb-2">
                    <x-button type="submit" variant="outline">Upload image</x-button>
                </form>

                @foreach ($customSectionImages as $image)
                    <div class="d-inline-block me-2 mb-2 text-center">
                        <img src="{{ asset($image->path) }}" alt="" style="width:80px;height:80px;object-fit:cover;" class="rounded mb-1 d-block">
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.custom-section.remove', [$workspaceUid, $businessUid, $image->uid]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
@endsection
