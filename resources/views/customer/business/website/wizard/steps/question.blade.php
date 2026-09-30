@extends('layouts/contentLayoutMaster')

@section('title', $step['prompt'])

@section('content')
    @php
        $backUrl = route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $previousStepKey]);
        $rows = is_array($answer) ? $answer : [];
        // A fixed number of blank rows for a repeatable question — the
        // simplest JS-free way to let an owner add several structured
        // entries on one screen; empty rows are dropped on submit
        // (WebsiteWizardController::valueFromRequest()). A richer
        // dynamic "add another" interaction is a natural follow-up, not
        // required to prove the underlying pipeline end to end.
        $blankRowCount = max(3, count($rows) + 1);
    @endphp

    <div class="row justify-content-center">
        <div class="col-lg-7">
            @include('customer.business.website.wizard._progress', ['progress' => $progress, 'backUrl' => $backUrl])

            <x-flash-alert class="mb-3" />

            <h4 class="mb-1">{{ $step['prompt'] }}</h4>
            @if ($step['help_text'])
                <p class="text-caption mb-3">{{ $step['help_text'] }}</p>
            @endif

            <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.autosave', [$workspaceUid, $businessUid, $step['key']]) }}">
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
                        @for ($i = 0; $i < $blankRowCount; $i++)
                            @php $row = $rows[$i] ?? []; @endphp
                            <x-card class="mb-2">
                                <input type="hidden" name="items[{{ $i }}][key]" value="{{ $row['key'] ?? \Illuminate\Support\Str::random(12) }}">
                                <div class="mb-2">
                                    <label class="form-label">Name</label>
                                    <input type="text" name="items[{{ $i }}][name]" class="form-control" value="{{ $row['name'] ?? '' }}">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Description</label>
                                    <textarea name="items[{{ $i }}][description]" rows="2" class="form-control">{{ $row['description'] ?? '' }}</textarea>
                                </div>

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
                            </x-card>
                        @endfor
                        @break

                    @default
                        <input type="text" name="value" class="form-control mb-3" value="{{ old('value', is_string($answer) ? $answer : '') }}">
                @endswitch

                <div class="d-flex gap-2">
                    <x-button type="submit" variant="primary">Continue</x-button>
                    @if (! $step['required'])
                        <x-button type="submit" name="skip" value="1" variant="outline">Skip</x-button>
                    @endif
                </div>
            </form>
        </div>
    </div>
@endsection
