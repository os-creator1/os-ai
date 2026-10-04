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
            <textarea name="{{ $n('value') }}" rows="4" class="form-control" @if($step['required']) required @endif>{{ is_string($answer) ? $answer : '' }}</textarea>
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
            <select name="{{ $n('value') }}" class="form-select" @if($step['required']) required @endif>
                <option value="">Choose one&hellip;</option>
                @foreach ($step['options'] ?? [] as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected($answer === $optionValue)>{{ $optionLabel }}</option>
                @endforeach
            </select>
            @break

        @case('multi_select')
            <div>
                @foreach ($step['options'] ?? [] as $optionValue => $optionLabel)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="{{ $n('value') }}[]" id="{{ $stepKey }}-{{ $optionValue }}" value="{{ $optionValue }}" @checked(is_array($answer) && in_array($optionValue, $answer, true))>
                        <label class="form-check-label" for="{{ $stepKey }}-{{ $optionValue }}">{{ $optionLabel }}</label>
                    </div>
                @endforeach
            </div>
            @break

        @case('string_list')
            @include('customer.business.website.wizard.steps._string-list', ['entries' => is_array($answer) ? $answer : [], 'fieldName' => $n('value')])
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

    @if ($step['target_module'] === 'knowledge_profile' && ($step['target_field'] ?? null) === 'testimonials' && ! empty($reviewSource))
        @include('customer.business.website.wizard.steps._review-sources', ['reviewSource' => $reviewSource])
    @endif
</div>
