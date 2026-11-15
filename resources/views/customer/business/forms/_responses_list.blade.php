{{-- Populated responses list shared by the per-form Submissions tab and the All
     responses page. $submissions is ALREADY bounded to the Locations this actor may
     reach (FormSubmissionReader over LocationAccessGuard); nothing here counts or
     names any other Location's responses. $showForm: show the original form's name
     (All responses) — the per-form tab already says which form it is. --}}
@php $showForm = $showForm ?? false; @endphp
<div class="mgf-list" data-role="forms-submissions">
    @foreach ($submissions as $submission)
        @php
            $answers = $submission->version ? \App\Library\Forms\FormAnswerPresenter::keyAnswers($submission->version, $submission->values ?? []) : [];
            $person = $submission->version ? \App\Library\Forms\FormAnswerPresenter::personName($submission->version, $submission->values ?? []) : '';
            $phone = $submission->contact?->phone ?? ($submission->contact_resolution->value === 'ambiguous' ? 'Needs review' : '—');
            $summary = collect($answers)->map(fn ($a) => $a['label'].': '.$a['value'])->implode(' · ');
            $url = route('customer.workspaces.businesses.forms.submissions.show', array_merge($scope, [$submission->uid]));
        @endphp
        <a class="mgf-row" href="{{ $url }}" data-submission="{{ $submission->uid }}" style="color:inherit">
            <span class="mgf-avatar" aria-hidden="true">@include('customer.business.forms._icon', ['n' => 'form'])</span>
            <span class="mgf-row-main">
                <b>{{ $person !== '' ? $person : $phone }}@if ($person !== '') <span style="font-weight:400;color:var(--mgf-mute);font-size:13px">· {{ $phone }}</span>@endif</b>
                <span>
                    @if ($showForm){{ $submission->form?->name }} · @endif{{ $submission->location?->name ?: 'Unnamed location' }} · {{ $submission->created_at?->format('M j, Y g:i A') }}
                </span>
                @if ($summary !== '')<span data-role="forms-key-answers">{{ $summary }}</span>@endif
            </span>
            <span class="mgf-row-meta" data-role="forms-submission-status">
                @if ($submission->contact_resolution->value === 'ambiguous')
                    <span class="mgf-pill is-warn">Needs review</span>
                @elseif ($submission->contact)
                    <span class="mgf-pill is-ok">Contact {{ $submission->contact_resolution->value === 'created' ? 'created' : 'matched' }}</span>
                @else
                    <span class="mgf-pill">No contact</span>
                @endif
                @if ($submission->crm_opportunity_id)<span class="mgf-pill is-brand">Opportunity</span>@endif
                <span class="mgf-link" aria-label="Open this response">Open</span>
            </span>
        </a>
    @endforeach
</div>
<div class="mgf-pager">{{ $submissions->appends(array_filter(['location' => $selectedLocationUid, 'form' => $form?->uid]))->links() }}</div>
