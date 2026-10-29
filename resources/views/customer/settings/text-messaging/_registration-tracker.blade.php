{{--
    Four-step tracker for the text messaging registration screen. Presentation only:
    every state is derived from values the page already has (the owned number, the
    saved registration status and the carrier-assignment status). No state is
    invented and nothing here changes a registration.
    Inputs: $phoneNumber, $status, $campaignAssignmentStatus (optional).
--}}
@php
    $assignment = $campaignAssignmentStatus ?? null;
    $hasNumber = filled($phoneNumber);
    $submitted = in_array($status, ['pending', 'approved'], true);
    $rejected = $status === 'rejected';
    $texting = $status === 'approved' && $hasNumber && ! in_array($assignment, ['requested', 'failed'], true);

    $trackerSteps = [
        ['title' => 'Number ready', 'sub' => $hasNumber ? 'Number purchased' : 'Choose a number', 'state' => $hasNumber ? 'done' : 'current'],
        ['title' => 'Registration', 'sub' => $submitted ? 'Submitted' : ($rejected ? 'Needs correction' : 'Your details'), 'state' => $submitted ? 'done' : ($rejected ? 'problem' : ($hasNumber ? 'current' : 'idle'))],
        ['title' => 'Carrier review', 'sub' => $status === 'approved' ? 'Approved' : ($status === 'pending' ? 'In review' : ($rejected ? 'Not approved' : 'Not started')), 'state' => $status === 'approved' ? 'done' : ($status === 'pending' ? 'current' : ($rejected ? 'problem' : 'idle'))],
        ['title' => 'Ready to text', 'sub' => $texting ? 'Texting enabled' : ($status === 'approved' && $assignment === 'failed' ? 'Needs our help' : ($status === 'approved' && $hasNumber ? 'Finishing setup' : 'Not yet')), 'state' => $texting ? 'done' : ($status === 'approved' && $hasNumber ? 'current' : 'idle')],
    ];
@endphp
<ol class="pm-tracker" aria-label="Registration progress" data-role="registration-tracker">
    @foreach($trackerSteps as $i => $step)
        <li class="pm-tracker__step is-{{ $step['state'] }}" @if($step['state'] === 'current') aria-current="step" @endif>
            <span class="pm-tracker__dot" aria-hidden="true">@if($step['state'] === 'done')&#10003;@elseif($step['state'] === 'problem')!@else{{ $i + 1 }}@endif</span>
            <span class="pm-tracker__text">
                <span class="pm-tracker__title">{{ $step['title'] }}</span>
                <span class="pm-tracker__sub">{{ $step['sub'] }}<span class="visually-hidden"> ({{ $step['state'] === 'done' ? 'complete' : ($step['state'] === 'current' ? 'current step' : ($step['state'] === 'problem' ? 'needs attention' : 'not started')) }})</span></span>
            </span>
        </li>
    @endforeach
</ol>
