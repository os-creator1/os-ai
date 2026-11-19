{{--
    Toll-free carrier review (verification submitted, awaiting the carrier). Same tm-* card,
    timeline and summary components as the local-number progress screen, with a compact
    port-out card beneath the summary. Presentation only: every value is the saved registration,
    the owned number and the existing refresh/port-out routes. Nothing is submitted from here.
    Inputs: $registration, $phoneNumber, $retainedNumbers, $portOutRequestsByNumberId, $workspaceUid, $businessUid.
--}}
@php
    // Display only: +18446980765 reads as +1 (844) 698-0765; anything unexpected is shown as stored.
    $prettyNumber = preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', (string) $phoneNumber, $m) === 1 ? '+1 (' . $m[1] . ') ' . $m[2] . '-' . $m[3] : (string) $phoneNumber;
    $ownedNumber = $retainedNumbers->first(fn ($n) => (string) $n->phone_number === (string) $phoneNumber) ?? $retainedNumbers->first();
    $monthly = $ownedNumber !== null && $ownedNumber->monthly_cost_micro !== null
        ? \App\Library\Dashboard\DashboardMoney::format((string) $ownedNumber->monthly_cost_micro, $ownedNumber->cost_currency ?: 'USD')
        : null;
    $lastChecked = $registration->campaign_status_checked_at ?? $registration->brand_status_checked_at;
    $billingUrl = route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid]);
    // Everything below is derived from the saved registration status, the owned number and the
    // carrier-assignment status. This screen is only rendered while the number is NOT yet ready to
    // text (a ready number gets its own screen), so "Ready to text" is never marked complete here.
    $tfStatus = $registration->status?->value ?? 'not_started';
    $assignment = $campaignAssignmentStatus ?? null;
    $rejectedReason = trim((string) $registration->rejection_reason);
    $tfSteps = match ($tfStatus) {
        'approved' => [
            ['role' => 'number', 'title' => 'Toll-free number', 'badge' => 'Done', 'state' => 'done', 'detail' => 'Purchased · ' . $prettyNumber],
            ['role' => 'verification', 'title' => 'Toll-free verification', 'badge' => 'Submitted', 'state' => 'done', 'detail' => 'Your business details were sent to the carrier.'],
            ['role' => 'review', 'title' => 'Carrier review', 'badge' => 'Approved', 'state' => 'done', 'detail' => 'The carrier approved your verification.'],
            ['role' => 'ready', 'title' => 'Ready to text', 'badge' => $assignment === 'failed' ? 'Needs our help' : 'Finishing setup', 'state' => $assignment === 'failed' ? 'problem' : 'active', 'detail' => $assignment === 'failed' ? 'We could not confirm the carrier finished enabling this number. Our team has been notified.' : 'You can send and receive texts as soon as setup is confirmed.'],
        ],
        'rejected' => [
            ['role' => 'number', 'title' => 'Toll-free number', 'badge' => 'Done', 'state' => 'done', 'detail' => 'Purchased · ' . $prettyNumber],
            ['role' => 'verification', 'title' => 'Toll-free verification', 'badge' => 'Submitted', 'state' => 'done', 'detail' => 'Your business details were sent to the carrier.'],
            ['role' => 'review', 'title' => 'Carrier review', 'badge' => 'Needs correction', 'state' => 'problem', 'detail' => 'The carrier did not approve this request. Correct your details below and resubmit.'],
            ['role' => 'ready', 'title' => 'Ready to text', 'badge' => 'Next', 'state' => 'idle', 'detail' => 'You can send and receive texts once the carrier approves.'],
        ],
        default => [
            ['role' => 'number', 'title' => 'Toll-free number', 'badge' => 'Done', 'state' => 'done', 'detail' => 'Purchased · ' . $prettyNumber],
            ['role' => 'verification', 'title' => 'Toll-free verification', 'badge' => 'Submitted', 'state' => 'done', 'detail' => 'Your business details were sent to the carrier.'],
            ['role' => 'review', 'title' => 'Carrier review', 'badge' => 'In review', 'state' => 'active', 'detail' => 'This usually takes a few business days.'],
            ['role' => 'ready', 'title' => 'Ready to text', 'badge' => 'Next', 'state' => 'idle', 'detail' => 'You can send and receive texts once the carrier approves.'],
        ],
    };
    $currentStep = match ($tfStatus) { 'approved' => 4, default => 3 };
    $verificationChip = match ($tfStatus) { 'approved' => ['Approved', 'success'], 'rejected' => ['Needs correction', 'danger'], default => ['Pending review', 'warn'] };
@endphp
<div class="tm-grid" data-role="registration-progress" data-step="{{ $currentStep }}">
    <section class="tm-card" aria-labelledby="tm-progress-title">
        <header class="tm-card__head">
            <h2 id="tm-progress-title" class="tm-card__title">Progress</h2>
            <span class="tm-card__meta" data-role="step-counter">Step {{ $currentStep }} of 4</span>
        </header>
        <ol class="tm-steps">
            @foreach($tfSteps as $i => $step)
                <li class="tm-step tm-step--{{ $step['state'] }}" data-role="stage-{{ $step['role'] }}" @if($step['state'] === 'active') aria-current="step" @endif>
                    <span class="tm-step__mark" aria-hidden="true">
                        @if($step['state'] === 'done') <x-ds-icon name="check" size="14" />
                        @elseif($step['state'] === 'problem') <x-ds-icon name="alert-circle" size="14" />
                        @elseif($step['state'] === 'active') <span class="tm-step__dot"></span>
                        @else {{ $i + 1 }}
                        @endif
                    </span>
                    <div class="tm-step__body">
                        <div class="tm-step__line">
                            <strong>{{ $step['title'] }}</strong>
                            <span class="sr-chip {{ $step['state'] === 'done' ? 'sr-chip--success' : ($step['state'] === 'active' ? 'sr-chip--warn' : ($step['state'] === 'problem' ? 'sr-chip--danger' : '')) }}">{{ $step['badge'] }}</span>
                        </div>
                        <p class="tm-step__text" data-role="detail-{{ $step['role'] }}">{{ $step['detail'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
        <div class="tm-card__foot">
            <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.refresh', [$workspaceUid, $businessUid]) }}" class="m-0">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary tm-refresh" data-role="refresh-status"><x-ds-icon name="refresh-cw" size="14" aria-hidden="true" /> Refresh status</button>
            </form>
            <span class="tm-card__meta" data-role="last-checked">
                @if($lastChecked)
                    Last checked {{ $lastChecked->diffForHumans() }}
                @else
                    Not checked with the carrier yet. We check periodically, and you can refresh at any time.
                @endif
            </span>
        </div>
    </section>

    <div class="tm-side">
        <aside class="tm-card tm-card--summary" aria-labelledby="tm-summary-title">
            <header class="tm-card__head"><h2 id="tm-summary-title" class="tm-card__title">Summary</h2></header>
            <dl class="tm-summary">
                <div><dt>Phone number</dt><dd data-role="phone-number">{{ $prettyNumber }}</dd></div>
                <div><dt>Type</dt><dd data-role="number-type">Toll-free</dd></div>
                <div><dt>Verification</dt><dd><span class="sr-chip sr-chip--{{ $verificationChip[1] }}" data-role="registration-status">{{ $verificationChip[0] }}</span></dd></div>
                <div><dt>Can send texts</dt><dd data-role="can-send"><span class="sr-chip">Not yet</span></dd></div>
                @if($monthly !== null)
                    <div><dt>Monthly cost</dt><dd data-role="monthly-cost">{{ $monthly }} · <a href="{{ $billingUrl }}">Billing &rarr;</a></dd></div>
                @else
                    <div><dt>Billing</dt><dd><a href="{{ $billingUrl }}">Usage &amp; billing &rarr;</a></dd></div>
                @endif
            </dl>
        </aside>

        @if($retainedNumbers->isNotEmpty())
            <aside class="tm-card tm-card--port" aria-labelledby="tm-port-title" data-role="port-out-compact">
                @foreach($retainedNumbers as $retainedNumber)
                    @php($portOutRequest = $portOutRequestsByNumberId[$retainedNumber->id] ?? null)
                    <div class="tm-port" data-role="port-out-number-row" data-number-id="{{ $retainedNumber->id }}">
                        <div class="tm-port__text">
                            @if($loop->first)<h2 id="tm-port-title" class="tm-port__title">Move to another carrier</h2>@endif
                            @if($portOutRequest !== null)
                                <p data-role="port-out-status">Request received on {{ $portOutRequest->created_at?->format('Y-m-d') }}. Our team will follow up with next steps.</p>
                            @else
                                <p>Port this number out any time.</p>
                            @endif
                        </div>
                        @if($portOutRequest !== null)
                            <form method="POST" action="{{ route('customer.workspaces.businesses.text-messaging.number.port-out.cancel', [$workspaceUid, $businessUid]) }}" class="m-0">
                                @csrf
                                <input type="hidden" name="number_id" value="{{ $retainedNumber->id }}">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Cancel request</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('customer.workspaces.businesses.text-messaging.number.port-out.request', [$workspaceUid, $businessUid]) }}" class="m-0">
                                @csrf
                                <input type="hidden" name="number_id" value="{{ $retainedNumber->id }}">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="Request to port this number out ({{ $retainedNumber->phone_number }})">Port out</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </aside>
        @endif
    </div>
</div>

@if($tfStatus === 'rejected')
    <x-alert variant="danger" icon="alert-circle" class="mt-3 mb-0" data-role="rejection-reason">
        <strong class="d-block">This needs a correction</strong>
        @if($rejectedReason !== '')
            {{ $rejectedReason }}
        @else
            The carrier did not supply a detailed reason for this rejection. Please review the details below and try again.
        @endif
    </x-alert>
@elseif($tfStatus === 'approved' && $assignment === 'failed')
    <x-alert variant="danger" icon="alert-circle" class="mt-3 mb-0" data-role="campaign-assignment-status">
        <strong class="d-block">We couldn't confirm your number is enabled yet</strong>
        Your number has already been purchased and charged, but we could not confirm the carrier finished enabling it for messaging. Our team has been notified and will follow up — texting will not be available on this number until that is resolved.
    </x-alert>
@elseif($tfStatus === 'approved' && $assignment === 'requested')
    <x-alert variant="info" icon="clock" class="mt-3 mb-0" data-role="campaign-assignment-status">
        <strong class="d-block">Almost ready</strong>
        Your verification is approved and your number has been purchased. We're waiting for the carrier to confirm it's enabled for messaging — texting becomes available as soon as that's confirmed.
    </x-alert>
@endif
