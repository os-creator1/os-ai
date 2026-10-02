@extends('layouts/contentLayoutMaster')

@section('title', $editMode ? 'Save your updated answers' : 'Review your answers')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <x-flash-alert class="mb-3" />

            @if ($editMode)
                <div class="text-center">
                    <x-empty-state icon="sparkles" title="Save your updated answers" description="We'll update your business information, packages, services and backdrops from your changes. Your existing website pages will not be regenerated or overwritten.">
                        <x-slot name="action">
                            <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.generate', [$workspaceUid, $businessUid]) }}">
                                @csrf
                                <input type="hidden" name="answers_revision" value="{{ $answersRevision }}">
                                <x-button type="submit" variant="primary">Save changes</x-button>
                            </form>
                        </x-slot>
                    </x-empty-state>
                </div>
            @else
                <h4 class="mb-1">Review your answers</h4>
                <p class="text-caption mb-3">This is what we'll build your website from. Look it over, then generate your first draft.</p>

                @if ($lastAttemptFailed)
                    <div class="alert alert-warning mb-3" role="alert" data-testid="generation-failed-notice">
                        Your website wasn't generated this time. Your answers are saved &mdash; nothing was lost.
                    </div>
                @elseif (! $generationAvailable)
                    <div class="alert alert-info mb-3" role="alert" data-testid="generation-unavailable-notice">
                        Website generation isn't available in this environment right now. You can still review and save your answers, and generate once it is.
                    </div>
                @endif

                @forelse ($answerSummary as $heading => $rows)
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6 class="mb-2">{{ $heading }}</h6>
                            <dl class="row mb-0">
                                @foreach ($rows as $row)
                                    <dt class="col-sm-5 text-caption fw-normal">{{ $row['prompt'] }}</dt>
                                    <dd class="col-sm-7">{{ $row['value'] }}</dd>
                                @endforeach
                            </dl>
                        </div>
                    </div>
                @empty
                    <p class="text-caption">No answers to show yet.</p>
                @endforelse

                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mt-4">
                    @if ($previousStepKey)
                        <a class="btn btn-outline-secondary" href="{{ route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $previousStepKey]) }}">Back and edit</a>
                    @else
                        <span></span>
                    @endif

                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.generate', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <input type="hidden" name="answers_revision" value="{{ $answersRevision }}">
                        <x-button type="submit" variant="primary">{{ $lastAttemptFailed ? 'Try again' : 'Generate my website' }}</x-button>
                    </form>
                </div>
            @endif
        </div>
    </div>
@endsection
