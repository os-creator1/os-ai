@extends('layouts/contentLayoutMaster')

@section('title', 'Form response')

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid];
        // Each answer is labelled by the questions of the version the response
        // was answered against — never the form's current questions — so an
        // edited or reworded form never rewrites an old response.
        $fields = $submission->version?->fields ?? [];
        $known = collect($fields)->pluck('key')->all();
    @endphp

    @include('customer.business.forms._messages')

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">{{ $submission->form?->name }}</h4>
            <p class="card-text text-muted">
                Received {{ $submission->created_at?->format('M j, Y g:i A') }} at
                <strong>{{ $submission->location?->name ?: 'Unnamed location' }}</strong>
                · form version {{ $submission->version?->version }}
            </p>

            @foreach ($submission->version->pages() as $page)
                @if ($submission->version->isMultiPage())
                    <h5 class="mt-1" data-role="forms-answers-page">{{ $page['title'] ?: 'Page '.($loop->iteration) }}</h5>
                @endif
                <dl class="row" data-role="forms-answers">
                    {{-- Headings, paragraphs, dividers and spacers collected nothing, so they have no answer to show. --}}
                    @foreach (array_filter($submission->version->fieldsOnPage($page['key']), fn ($f) => \App\Enums\Forms\FormFieldType::from($f['type'])->isInput()) as $field)
                        @php($text = \App\Library\Forms\FormAnswerPresenter::display($field, $submission->values[$field['key']] ?? null))
                        <dt class="col-sm-4">{{ $field['label'] }}</dt>
                        <dd class="col-sm-8" data-answer="{{ $field['key'] }}">
                            @if ($text === '')
                                <span class="text-muted">—</span>
                            @else
                                {!! nl2br(e($text)) !!}
                            @endif
                        </dd>
                    @endforeach
                </dl>
            @endforeach

            <p class="mb-1" data-role="forms-contact">
                @if ($submission->contact)
                    Contact: {{ $submission->contact->phone }}
                @elseif ($submission->contact_resolution->value === 'ambiguous')
                    Several contacts at this location share this phone number, so none was chosen. Please review.
                @else
                    No contact was linked (no phone number was given).
                @endif
            </p>
            @if ($submission->opportunity)
                <p class="mb-1" data-role="forms-opportunity">Opportunity: {{ $submission->opportunity->title }}</p>
            @endif

            <a href="{{ route('customer.workspaces.businesses.forms.submissions.index', $scope) }}?form={{ $submission->form?->uid }}" data-role="forms-back-to-responses">Back to responses</a>
        </div>
    </div>
@endsection
