{{--
    One atomic questionnaire step's input. Rendered inside the screen's
    single answer form. On a screen with several steps every field name is
    namespaced `s[<stepKey>][...]`; a single-step screen keeps the original
    un-namespaced names (`value`, `items[...]`) so every stored v1 session
    and every existing client keeps working.
--}}
@php
    $base = $isMultiStep ? 's[' . $step['key'] . ']' : null;
    $n = fn (string $name) => $base ? $base . '[' . $name . ']' : $name;
    $stepKey = $step['key'];
@endphp

<div class="mb-4" data-step="{{ $stepKey }}">
    <input type="hidden" name="__present[]" value="{{ $stepKey }}">
    @if ($isMultiStep)
        <label class="form-label h6 mb-1">
            {{ $step['prompt'] }}
            @unless ($step['required'])
                <span class="text-caption fw-normal">(optional)</span>
            @endunless
        </label>
    @endif
    @if ($isMultiStep && $step['help_text'])
        <p class="text-caption mb-2">{!! nl2br(e($step['help_text'])) !!}</p>
    @endif

    @switch($step['input_type'])
        @case('text')
        @case('tel')
        @case('email')
            <input type="{{ $step['input_type'] }}" name="{{ $n('value') }}" class="form-control" value="{{ is_string($answer) ? $answer : '' }}" @if($step['required']) required @endif>
            @break

        @case('textarea')
            @if (($step['defaults'] ?? null) === 'about')
                @include('customer.business.website.wizard.steps._about')
            @else
                <textarea name="{{ $n('value') }}" rows="4" class="form-control" @if($step['required']) required @endif>{{ is_string($answer) ? $answer : '' }}</textarea>
            @endif
            @break

        @case('boolean')
            <div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="{{ $n('value') }}" id="{{ $stepKey }}-yes" value="1" @checked($answer === true)>
                    <label class="form-check-label" for="{{ $stepKey }}-yes">Yes</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="{{ $n('value') }}" id="{{ $stepKey }}-no" value="0" @checked($answer === false)>
                    <label class="form-check-label" for="{{ $stepKey }}-no">No</label>
                </div>
            </div>
            @break

        @case('select')
            @if (count($step['options'] ?? []) <= 5)
                @include('customer.business.website.wizard.steps._choice-cards')
                @break
            @endif
            <select name="{{ $n('value') }}" class="form-select" @if($step['required']) required @endif>
                <option value="">Choose one&hellip;</option>
                @foreach ($step['options'] ?? [] as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected($answer === $optionValue)>{{ $optionLabel }}</option>
                @endforeach
            </select>
            @break

        @case('multi_select')
            @if ($step['target_module'] === 'knowledge_profile')
                {{-- Facts about the business: tappable cards, none preselected unless already confirmed. --}}
                <div class="row g-2">
                    @foreach ($step['options'] ?? [] as $optionValue => $optionLabel)
                        <div class="col-md-6">
                            <input type="checkbox" class="btn-check" name="{{ $n('value') }}[]" id="{{ $stepKey }}-{{ $optionValue }}" value="{{ $optionValue }}" autocomplete="off" @checked(is_array($answer) && in_array($optionValue, $answer, true))>
                            <label class="btn btn-outline-primary w-100 text-start py-3" for="{{ $stepKey }}-{{ $optionValue }}">{{ $optionLabel }}</label>
                        </div>
                    @endforeach
                </div>
                @break
            @endif
            @php $isFormFields = $step['target_module'] === 'website_form'; @endphp
            <div @if($isFormFields) data-form-fields data-recommended='@json(array_keys($step['options'] ?? []))' @endif>
                @foreach ($step['options'] ?? [] as $optionValue => $optionLabel)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="{{ $n('value') }}[]" id="{{ $stepKey }}-{{ $optionValue }}" value="{{ $optionValue }}" data-form-field-label="{{ $optionLabel }}" @checked(is_array($answer) && in_array($optionValue, $answer, true))>
                        <label class="form-check-label" for="{{ $stepKey }}-{{ $optionValue }}">{{ $optionLabel }}</label>
                    </div>
                @endforeach
                @if ($isFormFields)
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-primary" data-form-recommended>Use the recommended form</button>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-form-preview-toggle aria-expanded="false">Preview form</button>
                    </div>
                    <div class="border rounded p-3 mt-3 d-none" data-form-preview aria-live="polite">
                        <div class="text-caption mb-2">This is what a visitor will see. You can test-submit the live form from Website Studio once your site is generated.</div>
                        <div data-form-preview-fields></div>
                        <button type="button" class="btn btn-primary btn-sm mt-2" disabled>Request a quote</button>
                    </div>
                @endif
            </div>
            @break

        @case('location')
            @php
                $place = is_array($answer) ? $answer : [];
                $countries = app(\App\Library\Business\BusinessLocaleOptions::class)->countries();
            @endphp
            <div class="row g-3" data-location-fields>
                <div class="col-12">
                    <label class="form-label" for="{{ $stepKey }}-city">City</label>
                    <input type="text" id="{{ $stepKey }}-city" name="{{ $n('value') }}[city]" class="form-control" autocomplete="address-level2" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_LIST_ITEM_LENGTH }}" value="{{ $place['city'] ?? '' }}" @if($step['required']) required @endif>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="{{ $stepKey }}-region">State / province / region</label>
                    <input type="text" id="{{ $stepKey }}-region" name="{{ $n('value') }}[region]" class="form-control" autocomplete="address-level1" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_LIST_ITEM_LENGTH }}" value="{{ $place['region'] ?? '' }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="{{ $stepKey }}-country">Country</label>
                    <select id="{{ $stepKey }}-country" name="{{ $n('value') }}[country_code]" class="form-select" autocomplete="country" @if($step['required']) required @endif>
                        <option value="">Choose a country&hellip;</option>
                        @foreach ($countries as $code => $countryLabel)
                            <option value="{{ $code }}" @selected(($place['country_code'] ?? null) === $code)>{{ $countryLabel }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @break

        @case('image')
            @php
                $logoUid = $website?->theme['logo_asset_uid'] ?? null;
                $logoAsset = $logoUid ? $website->assets()->where('uid', $logoUid)->first() : null;
                $logoUrl = $logoAsset ? \App\Library\Website\Media\WebsiteMediaPayload::thumbUrl($logoAsset) : null;
            @endphp
            <div data-logo-box
                 data-upload-url="{{ route('customer.workspaces.businesses.website.setup.logo.upload', [$workspaceUid, $businessUid]) }}"
                 data-remove-url="{{ route('customer.workspaces.businesses.website.setup.logo.remove', [$workspaceUid, $businessUid]) }}">
                <div class="d-flex align-items-center gap-3">
                    <img src="{{ $logoUrl }}" alt="{{ $logoAsset?->alt_text ?: 'Your logo' }}" class="rounded border {{ $logoUrl ? '' : 'd-none' }}" style="max-width:160px;max-height:80px;object-fit:contain;" data-logo-preview>
                    <div>
                        <label class="btn btn-outline-primary btn-sm mb-0" data-logo-add>{{ $logoUrl ? 'Replace logo' : '+ Upload logo' }}
                            <input type="file" hidden accept="image/png,image/jpeg,image/webp" data-logo-input>
                        </label>
                        <button type="button" class="btn btn-outline-danger btn-sm {{ $logoUrl ? '' : 'd-none' }}" data-logo-remove>Remove</button>
                        <div class="progress mt-2 d-none" style="height:4px;width:160px;"><div class="progress-bar" data-upload-progress></div></div>
                        <div class="text-danger small mt-1 d-none" data-upload-error></div>
                    </div>
                </div>
                <p class="text-caption mt-2 mb-0">Optional. PNG, JPEG or WEBP up to 8 MB. Your logo appears in your website&rsquo;s header.</p>
            </div>
            @break

        @case('string_list')
            @php
                $wantsAreaDetails = (bool) ($step['area_details'] ?? false);
                $areaPhotos = $wantsAreaDetails
                    ? $website->assets()->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::Gallery->value)->orderBy('sort_order')->orderBy('id')->limit(40)->get()
                        ->map(fn ($asset) => ['uid' => $asset->uid, 'url' => \App\Library\Website\Media\WebsiteMediaPayload::thumbUrl($asset), 'alt' => (string) ($asset->alt_text ?? '')])->all()
                    : [];
            @endphp
            @include('customer.business.website.wizard.steps._string-list', [
                'entries' => is_array($answer) ? $answer : [],
                'fieldName' => $n('value'),
                'areaState' => $areaState[$stepKey] ?? null,
                'areaDetailsName' => $wantsAreaDetails ? $n('area_details') : null,
                'areaDetailsSaved' => $wantsAreaDetails ? ($answers[\App\Library\Website\Setup\ServiceAreaDetails::ANSWER_KEY] ?? []) : [],
                'areaPhotos' => $areaPhotos,
            ])
            @break

        @case('repeatable_group')
            @include('customer.business.website.wizard.steps._repeatable', ['rows' => is_array($answer) ? $answer : [], 'fieldBase' => $n('items')])
            @break

        @case('catalog_selection')
            @include('customer.business.website.wizard.steps._package-selector', ['packageRows' => $packageRows[$stepKey] ?? [], 'fieldBase' => $n('items')])
            @break

        @case('photo_upload')
            <input type="hidden" name="{{ $n('value') }}" value="{{ $website->assets()->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::Gallery->value)->count() }}">
            @include('customer.business.website.wizard.steps._gallery')
            @break

        @default
            <input type="text" name="{{ $n('value') }}" class="form-control" value="{{ is_string($answer) ? $answer : '' }}">
    @endswitch

    @if (! empty($step['shared_fact']))
        <p class="text-caption mt-2 mb-0" data-shared-note>
            <x-ds-icon name="refresh-cw" size="14" aria-hidden="true" />
            Shared with the rest of MotionGrove &mdash; changing this updates your business profile everywhere, not just your website.
        </p>
    @endif

    @if ($step['target_module'] === 'knowledge_profile' && ($step['target_field'] ?? null) === 'testimonials' && ! empty($reviewSource))
        @include('customer.business.website.wizard.steps._review-sources', ['reviewSource' => $reviewSource])
    @endif
</div>
