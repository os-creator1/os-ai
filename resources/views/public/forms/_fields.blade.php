{{-- Forms — the ONE renderer of a form's elements. Used by the real public page
     (public.forms.show) and by the builder's Preview, so what an owner previews
     is what a visitor gets. Presentation only: $fields are the (pinned) version's
     elements, $answers the values to pre-fill. Everything is escaped.
     Content blocks (heading, paragraph, divider, spacer) present, and collect nothing. --}}
@php
    $answers = $answers ?? [];
@endphp
<div class="pf-row">
@foreach ($fields as $field)
    @php
        $type = $field['type'];
        $key = $field['key'];
        $half = ($field['width'] ?? null) === 'half';
        $required = ! empty($field['required']);
        $current = old($key, $answers[$key] ?? ($field['default'] ?? null));
        $describedBy = ! empty($field['help']) ? 'h-'.$key : null;
        $inputType = ['email' => 'email', 'phone' => 'tel', 'date' => 'date', 'datetime' => 'datetime-local', 'number' => 'number', 'currency' => 'number'][$type] ?? 'text';
        $extra = ['number' => 'step=any', 'currency' => 'step=0.01 min=0 inputmode=decimal'][$type] ?? '';
    @endphp
    <div class="pf-item {{ $half ? 'pf-half' : '' }}" data-field-key="{{ $key }}" data-field-type="{{ $type }}">
    @switch($type)
        @case('heading')
            <h2 class="pf-heading">{{ $field['label'] }}</h2>
            @break
        @case('paragraph')
            <p class="pf-paragraph">{!! nl2br(e($field['label'])) !!}</p>
            @break
        @case('divider')
            <hr class="pf-divider">
            @break
        @case('spacer')
            <div class="pf-spacer" aria-hidden="true"></div>
            @break
        @case('checkbox')
        @case('consent_transactional')
        @case('consent_marketing')
            {{-- A checkbox is never pre-checked from a default: only a visitor's own earlier answer re-fills it. --}}
            <label class="pf-choice {{ str_starts_with($type, 'consent_') ? 'pf-consent' : '' }}">
                <input type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $answers[$key] ?? false)) @required($required)>
                <span>{{ $field['label'] }}@if($required) <span class="pf-req">*</span>@endif</span>
            </label>
            @break
        @case('radio')
        @case('yes_no')
            @php
            $choices = $type === 'yes_no' ? ['Yes', 'No'] : $field['options'];
            @endphp
            <span class="pf-label" id="l-{{ $key }}">{{ $field['label'] }}@if($required) <span class="pf-req">*</span>@endif</span>
            <div role="radiogroup" aria-labelledby="l-{{ $key }}">
                @foreach ($choices as $choice)
                    <label class="pf-choice"><input type="radio" name="{{ $key }}" value="{{ $choice }}" @checked($current === $choice) @required($required)> <span>{{ $choice }}</span></label>
                @endforeach
            </div>
            @break
        @case('multi_select')
            @php
            $picked = is_array($current) ? $current : [];
            @endphp
            <span class="pf-label" id="l-{{ $key }}">{{ $field['label'] }}@if($required) <span class="pf-req">*</span>@endif</span>
            <div role="group" aria-labelledby="l-{{ $key }}">
                @foreach ($field['options'] as $choice)
                    <label class="pf-choice"><input type="checkbox" name="{{ $key }}[]" value="{{ $choice }}" @checked(in_array($choice, $picked, true))> <span>{{ $choice }}</span></label>
                @endforeach
            </div>
            @break
        @default
            <label class="pf-label" for="f-{{ $key }}">{{ $field['label'] }}@if($required) <span class="pf-req">*</span>@endif</label>
            @if ($type === 'textarea')
                <textarea class="pf-textarea" id="f-{{ $key }}" name="{{ $key }}" @required($required) placeholder="{{ $field['placeholder'] ?? '' }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif>{{ $current }}</textarea>
            @elseif ($type === 'select')
                <select class="pf-select" id="f-{{ $key }}" name="{{ $key }}" @required($required) @if($describedBy) aria-describedby="{{ $describedBy }}" @endif>
                    <option value="">{{ $field['placeholder'] ?? 'Choose…' }}</option>
                    @foreach ($field['options'] as $option)
                        <option value="{{ $option }}" @selected($current === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            @else

                <input class="pf-input" id="f-{{ $key }}" type="{{ $inputType }}" name="{{ $key }}" value="{{ $current }}" {{ $extra }} @required($required) placeholder="{{ $field['placeholder'] ?? '' }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif>
            @endif
    @endswitch
    @if (! empty($field['help']))
        <small class="pf-help" id="h-{{ $key }}">{{ $field['help'] }}</small>
    @endif
    </div>
@endforeach
</div>
