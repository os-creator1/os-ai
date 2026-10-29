{{--
    Text messaging registration progress (local / 10DLC): a three-step timeline
    plus a compact summary. Two carrier stages (business verification, messaging
    campaign) come only from saved carrier state (RegistrationProgress); the
    third step is phone-number setup, never another carrier verification.
    Inputs: $progress, $registration, $phoneNumber, $campaignAssignmentStatus,
    $workspaceUid, $businessUid.
--}}
@php
    $brandKey = $progress['brand']['key'];
    $campaignKey = $progress['campaign']['key'];
    $chipVariants = ['verified' => 'success', 'approved' => 'success', 'needs_attention' => 'danger', 'issue' => 'warn', 'overdue' => 'warn', 'processing' => 'warn', 'unconfirmed' => 'warn', 'under_review' => 'warn', 'ready' => 'accent', 'not_started' => ''];
    $campaignApproved = $campaignKey === 'approved';
    $brandDone = $brandKey === 'verified';
    $hasNumber = filled($phoneNumber);

    // Step 3 (number setup) only opens once the carrier has approved the campaign.
    $numberKey = match (true) {
        $campaignApproved && $hasNumber => 'done',
        $campaignApproved => 'ready',
        default => 'next',
    };
    $currentStep = match (true) {
        $numberKey === 'done' => 3,
        $campaignApproved => 3,
        $brandDone => 2,
        default => 1,
    };

    $steps = [
        [
            'role' => 'brand', 'key' => $brandKey, 'title' => $brandDone ? 'Business verified' : 'Business verification',
            'badge' => $brandDone ? 'Done' : $progress['brand']['label'],
            'state' => $brandDone ? 'done' : ($brandKey === 'needs_attention' ? 'problem' : ($brandKey === 'not_started' ? 'idle' : 'active')),
            'detail' => $progress['brand']['detail'],
        ],
        [
            'role' => 'campaign', 'key' => $campaignKey, 'title' => 'Messaging campaign',
            'badge' => match ($campaignKey) {
                'approved' => 'Approved', 'under_review' => 'In review', 'needs_attention' => 'Needs correction',
                'issue' => 'Submission issue', 'ready' => 'Ready to submit', default => 'Not submitted',
            },
            'state' => match ($campaignKey) {
                'approved' => 'done', 'needs_attention', 'issue' => 'problem', 'under_review', 'ready' => 'active', default => 'idle',
            },
            'detail' => $campaignKey === 'under_review'
                ? 'Submitted and waiting for the carrier. This usually takes a few business days.'
                : $progress['campaign']['detail'],
        ],
        [
            'role' => 'number', 'key' => $numberKey, 'title' => 'Choose a phone number',
            'badge' => ['done' => 'Done', 'ready' => 'Ready', 'next' => 'Next'][$numberKey],
            'state' => ['done' => 'done', 'ready' => 'active', 'next' => 'idle'][$numberKey],
            'detail' => match ($numberKey) {
                'done' => 'Your number is ' . $phoneNumber . '.',
                'ready' => 'The campaign is approved. Choose your number to finish setup.',
                default => 'You can pick a number once the campaign is approved.',
            },
        ],
    ];

    $registrationLabel = match (true) {
        $campaignApproved => ['Approved', 'success'],
        $campaignKey === 'under_review' => ['Pending review', 'warn'],
        $campaignKey === 'needs_attention' => ['Needs correction', 'danger'],
        $campaignKey === 'issue' => ['Submission issue', 'warn'],
        $campaignKey === 'ready' => ['Ready to submit', 'accent'],
        $brandKey === 'needs_attention' => ['Needs attention', 'danger'],
        in_array($brandKey, ['processing', 'overdue', 'unconfirmed'], true) => ['Verifying business', 'warn'],
        default => ['Not started', ''],
    };
@endphp
<div class="tm-grid" data-role="registration-progress" data-step="{{ $currentStep }}">
    <section class="tm-card" aria-labelledby="tm-progress-title">
        <header class="tm-card__head">
            <h2 id="tm-progress-title" class="tm-card__title">Progress</h2>
            <span class="tm-card__meta" data-role="step-counter">Step {{ $currentStep }} of 3</span>
        </header>
        <ol class="tm-steps">
            @foreach($steps as $i => $step)
                <li class="tm-step tm-step--{{ $step['state'] }}" data-role="stage-{{ $step['role'] }}" data-state="{{ $step['key'] }}" @if($step['state'] === 'active') aria-current="step" @endif>
                    <span class="tm-step__mark" aria-hidden="true">
                        @if($step['state'] === 'done') <x-ds-icon name="check" size="14" />
                        @elseif($step['state'] === 'problem') <x-ds-icon name="alert-circle" size="14" />
                        @elseif($step['state'] === 'active' && $step['role'] !== 'number') <span class="tm-step__dot"></span>
                        @else {{ $i + 1 }}
                        @endif
                    </span>
                    <div class="tm-step__body">
                        <div class="tm-step__line">
                            <strong>{{ $step['title'] }}</strong>
                            <span class="sr-chip {{ $step['state'] === 'done' ? 'sr-chip--success' : ($step['state'] === 'problem' ? 'sr-chip--danger' : ($step['state'] === 'active' ? 'sr-chip--warn' : '')) }}">{{ $step['badge'] }}</span>
                        </div>
                        @if($step['role'] === 'brand' && $progress['tcr_brand_id'])
                            <p class="tm-step__text">Carrier brand ID <span class="tm-mono" data-role="tcr-brand-id">{{ $progress['tcr_brand_id'] }}</span></p>
                        @endif
                        @if(! ($step['role'] === 'brand' && $brandDone))
                            <p class="tm-step__text" data-role="detail-{{ $step['role'] }}">{{ $step['detail'] }}</p>
                        @endif
                        @if($step['role'] === 'number' && $numberKey === 'ready')
                            <a class="btn btn-sm btn-primary mt-1" data-role="choose-number" href="{{ route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid]) }}">Choose a number</a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
        <footer class="tm-card__foot">
            <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.refresh', [$workspaceUid, $businessUid]) }}" class="m-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary tm-refresh" data-role="refresh-status"><x-ds-icon name="refresh-cw" size="14" aria-hidden="true" /> Refresh status</button>
            </form>
            <span class="tm-card__meta" data-role="last-checked">
                @if($progress['last_checked_at'])
                    Last checked {{ $progress['last_checked_at']->diffForHumans() }}
                @else
                    Not checked with the carrier yet. We check periodically, and you can refresh at any time.
                @endif
            </span>
        </footer>
    </section>

    <aside class="tm-card tm-card--summary" aria-labelledby="tm-summary-title">
        <header class="tm-card__head"><h2 id="tm-summary-title" class="tm-card__title">Summary</h2></header>
        <dl class="tm-summary">
            <div><dt>Phone number</dt><dd data-role="phone-number">{{ $hasNumber ? $phoneNumber : 'Not chosen yet' }}</dd></div>
            <div><dt>Registration</dt><dd><span class="sr-chip {{ $registrationLabel[1] !== '' ? 'sr-chip--' . $registrationLabel[1] : '' }}" data-role="registration-status">{{ $registrationLabel[0] }}</span></dd></div>
            <div><dt>Can send texts</dt><dd data-role="can-send"><span class="sr-chip">Not yet</span></dd></div>
        </dl>
    </aside>
</div>

@if($campaignApproved && $campaignAssignmentStatus === 'failed')
    <x-alert variant="danger" icon="alert-circle" class="mt-2 mb-0" data-role="campaign-assignment-status">
        <strong class="d-block">We couldn't confirm your number is enabled yet</strong>
        Your number has already been purchased and charged, but we could not confirm the carrier finished enabling it for messaging. Our team has been notified and will follow up — texting will not be available on this number until that is resolved.
    </x-alert>
@elseif($campaignApproved && $campaignAssignmentStatus === 'requested')
    <x-alert variant="info" icon="clock" class="mt-2 mb-0" data-role="campaign-assignment-status">
        <strong class="d-block">Almost ready</strong>
        Your business is verified and your number has been purchased. We're waiting for the carrier to confirm it's enabled for messaging — this is usually quick, and texting will become available on this number as soon as that's confirmed.
    </x-alert>
@endif
