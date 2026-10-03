@extends('layouts/contentLayoutMaster')

@section('title', $step['prompt'])

@section('content')
    @php
        $isMultiStep = count($steps) > 1;
        $answerFormId = 'answer-form-' . $screenKey;
        $allOptional = collect($steps)->every(fn ($s) => ! $s['required']);
        $hasCustomSection = collect($steps)->contains(fn ($s) => $s['target_module'] === 'custom_section');
    @endphp

    <div class="row justify-content-center">
        <div class="col-lg-7" data-wizard-screen>
            @include('customer.business.website.wizard._progress', ['progress' => $progress, 'backStepKey' => $screenKey, 'currentStepKey' => $screenKey])

            <x-flash-alert class="mb-3" />

            @unless ($isMultiStep)
                <h4 class="mb-1">{{ $step['prompt'] }}</h4>
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
                <x-button type="submit" form="{{ $answerFormId }}" variant="primary">Continue</x-button>
                @if ($allOptional)
                    <x-button type="submit" form="{{ $answerFormId }}" name="skip" value="1" variant="outline">Skip</x-button>
                @endif
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
