@extends('layouts/contentLayoutMaster')

@section('title', $step['screen_prompt'] ?? $step['prompt'])

@section('content')
    @php
        $isMultiStep = count($steps) > 1;
        $answerFormId = 'answer-form-' . $screenKey;
        $allOptional = collect($steps)->every(fn ($s) => ! $s['required']);
        $hasCustomSection = collect($steps)->contains(fn ($s) => $s['target_module'] === 'custom_section');
        // Canonical Packages & Products rows are written by an explicit save, never by a debounce tick.
        $debounceAutosave = ! collect($steps)->contains(fn ($s) => $s['input_type'] === 'catalog_selection');
    @endphp

    {{--
        `data-wizard-root` is the persistent shell: the navigation script lives
        for the whole session and only the `data-wizard-screen` element inside
        it is replaced when the owner moves between screens (no document reload).
    --}}
    <div class="row justify-content-center" data-wizard-root>
        <div class="col-lg-7" data-wizard-screen
             data-screen-key="{{ $screenKey }}"
             data-draft-url="{{ route('customer.workspaces.businesses.website.setup.draft', [$workspaceUid, $businessUid, $screenKey]) }}"
             data-visit-url="{{ route('customer.workspaces.businesses.website.setup.visit', [$workspaceUid, $businessUid, $screenKey]) }}"
             data-exit-url="{{ route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]) }}"
             data-autosave="{{ $debounceAutosave ? 'on' : 'off' }}">
            @include('customer.business.website.wizard._progress', ['progress' => $progress, 'backStepKey' => $screenKey, 'currentStepKey' => $screenKey])

            <x-flash-alert class="mb-3" />
            <div class="alert alert-danger d-none" role="alert" tabindex="-1" data-wizard-error></div>

            @if ($isMultiStep && ! empty($step['screen_prompt']))
                <h4 class="mb-1" tabindex="-1" data-wizard-heading>{{ $step['screen_prompt'] }}</h4>
                @if (! empty($step['screen_help']))
                    <p class="text-caption mb-3">{{ $step['screen_help'] }}</p>
                @endif
            @endif

            @unless ($isMultiStep)
                <h4 class="mb-1" tabindex="-1" data-wizard-heading>{{ $step['prompt'] }}</h4>
                @if ($step['help_text'])
                    <p class="text-caption mb-3">{!! nl2br(e($step['help_text'])) !!}</p>
                @endif
            @endunless

            {{--
                Every distinct submission target stays its own SIBLING
                element, never nested inside another form. The answer form
                holds only this screen's answer inputs; media controls
                (immediate upload, thumbnails, alt text) are plain
                script-driven controls, and the primary actions live in a
                bar BELOW all of the content being edited, associated with
                the answer form by id via `form=` (standard HTML5, not
                nesting) — Continue/Skip are never left floating above the
                upload controls.
            --}}
            <form id="{{ $answerFormId }}" method="POST" action="{{ route('customer.workspaces.businesses.website.setup.autosave', [$workspaceUid, $businessUid, $screenKey]) }}">
                @csrf
                <input type="hidden" name="answers_revision" value="{{ $response->answers_revision }}">

                @foreach ($steps as $screenStep)
                    @include('customer.business.website.wizard.steps._field', [
                        'step' => $screenStep,
                        'answer' => $answers[$screenStep['key']] ?? null,
                        'isMultiStep' => $isMultiStep,
                    ])
                @endforeach
            </form>

            <div class="d-flex flex-wrap gap-2 mt-4 mb-2" data-wizard-actions>
                <x-button type="submit" form="{{ $answerFormId }}" variant="primary" data-wizard-continue>Continue</x-button>
                @if ($allOptional)
                    <x-button type="submit" form="{{ $answerFormId }}" name="skip" value="1" variant="outline" data-wizard-skip>Skip</x-button>
                @endif
                <button type="button" class="btn btn-link ms-auto" data-wizard-exit>Save &amp; exit</button>
                @if ($hasCustomSection)
                    {{--
                        A SIBLING button associated with the answer form by id,
                        submitting that form's OWN currently-typed fields to the
                        improve action instead of the autosave action — it acts on
                        whatever the owner has typed right now, never on a stale
                        persisted value.
                    --}}
                    <button type="submit" form="{{ $answerFormId }}" formaction="{{ route('customer.workspaces.businesses.website.setup.custom-section.improve', [$workspaceUid, $businessUid]) }}" class="btn btn-outline-secondary">Improve with AI</button>
                @endif
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    @include('customer.business.website.wizard.steps._scripts')
@endsection
