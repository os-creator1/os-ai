@extends('layouts/contentLayoutMaster')

@section('title', $editMode ? 'Save your updated answers' : 'Review your answers')

@section('page-style')
    @include('partials.section-router._styles')
@endsection

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

                @if (! empty($templateCards))
                    @include('customer.business.website.wizard.steps._look')
                    @include('customer.business.website.wizard.steps._plan')
                @endif

                @forelse ($answerSummary as $heading => $blocks)
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6 class="mb-3">{{ $heading }}</h6>

                            @foreach ($blocks as $block)
                                <div class="mb-3" data-summary-block>
                                    <div class="d-flex justify-content-between align-items-baseline">
                                        <div class="text-caption">{{ $block['prompt'] }}</div>
                                        <a class="text-label" href="{{ route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $block['edit_key']]) }}">Edit</a>
                                    </div>

                                    @if (! empty($block['notice']))
                                        <div class="text-warning small mb-1" data-summary-notice>{{ $block['notice'] }}</div>
                                    @endif

                                    @if ($block['kind'] === 'text')
                                        <div>{{ $block['value'] }}</div>
                                    @elseif ($block['kind'] === 'photos' || $block['kind'] === 'backdrops')
                                        <div class="d-flex flex-wrap gap-3 mt-1">
                                            @foreach ($block['entries'] as $entry)
                                                <div class="text-center" style="width:96px;">
                                                    @if ($entry['thumb'])
                                                        <img src="{{ $entry['thumb'] }}" alt="{{ $entry['label'] }}" class="rounded d-block mb-1" style="width:96px;height:96px;object-fit:cover;">
                                                    @endif
                                                    <div class="small">{{ $entry['label'] }}</div>
                                                    @if ($entry['meta'])
                                                        <div class="text-caption small">{{ $entry['meta'] }}</div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <ul class="mb-0 ps-3">
                                            @foreach ($block['entries'] as $entry)
                                                <li>{{ $entry['label'] }}@if ($entry['meta']) <span class="text-caption">&mdash; {{ $entry['meta'] }}</span>@endif</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
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

                    <form method="POST" data-generation-form action="{{ route('customer.workspaces.businesses.website.setup.generate', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <input type="hidden" name="answers_revision" value="{{ $answersRevision }}">
                        <x-button type="submit" variant="primary">{{ $lastAttemptFailed ? 'Try again' : 'Generate my website' }}</x-button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    @include('customer.business.website._generation-progress')
@endsection
