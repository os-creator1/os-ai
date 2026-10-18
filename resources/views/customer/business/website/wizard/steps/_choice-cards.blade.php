{{-- A single choice (select) shown as large, tappable cards instead of a dropdown. Same `value` field. --}}
<div class="row g-2" role="radiogroup" aria-label="{{ $step['prompt'] }}">
    @foreach ($step['options'] ?? [] as $optionValue => $optionLabel)
        <div class="col-12">
            <input type="radio" class="btn-check" name="{{ $n('value') }}" id="{{ $stepKey }}-{{ $optionValue }}" value="{{ $optionValue }}" autocomplete="off" @checked((string) $answer === (string) $optionValue)>
            <label class="btn btn-outline-primary w-100 text-start py-3" for="{{ $stepKey }}-{{ $optionValue }}">{{ $optionLabel }}</label>
        </div>
    @endforeach
</div>
