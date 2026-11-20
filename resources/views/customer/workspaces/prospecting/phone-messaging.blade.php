@extends('layouts/contentLayoutMaster')

@section('title', 'Phone & Messaging')
@section('page-heading', 'own')

@section('content')
@php
    $hasManagedNumber = ($managed['phoneNumber'] ?? null) !== null;
    $managedReady = ($managed['state'] ?? null) === 'ready';
    $managedIsDefault = $hasManagedNumber && ! $defaultIsByo;
    $telnyx = $channels->where('provider', \App\Models\SendingServer::TYPE_TELNYX);

    // One normalised row per sending number (BYO Telnyx channels + the managed number), never claiming more than is known.
    $rows = [];
    foreach ($telnyx as $channel) {
        $state = $readiness[$channel->id] ?? ['ok' => false, 'message' => null];
        $hasKey = trim((string) $channel->webhook_public_key) !== '';
        $chips = [];
        $chips[] = $state['ok'] ? ['ok' => true, 'label' => 'Can send'] : ['ok' => false, 'label' => 'Needs attention', 'hint' => $state['message']];
        if (! $hasKey) {
            $chips[] = ['ok' => false, 'label' => 'Replies not set up', 'hint' => 'Add your Telnyx webhook public key so replies can be received.'];
        } elseif ($channel->webhook_verified_at) {
            $chips[] = ['ok' => true, 'label' => 'Replies working'];
        } else {
            $chips[] = ['ok' => false, 'label' => 'Waiting for first event from Telnyx', 'hint' => 'Set the webhook URL on the Manage page in your Telnyx messaging profile.'];
        }
        $chips[] = $channel->sms_capability_confirmed_at
            ? ['ok' => true, 'label' => 'Text messaging confirmed by Telnyx']
            : ['ok' => false, 'label' => 'Text messaging not confirmed', 'hint' => "Telnyx did not report this number's text-messaging status. Check in Telnyx that it can send texts."];
        $rows[] = [
            'kind' => 'byo', 'number' => $channel->sender_number, 'provider' => 'Your Telnyx account · billed by Telnyx',
            'current' => (bool) $channel->is_default_sender, 'chips' => $chips, 'sender' => $channel->uid,
            'manage' => route('customer.workspaces.prospecting.channels.show', [$workspaceUid, $channel->uid]), 'manageLabel' => 'Manage account',
            'disconnect' => route('customer.workspaces.prospecting.channels.disconnect', [$workspaceUid, $channel->uid]),
        ];
    }
    if ($business !== null && $hasManagedNumber) {
        $rows[] = [
            'kind' => 'managed', 'number' => $managed['phoneNumber'], 'provider' => 'Bought through us · billed to your wallet',
            'current' => $managedIsDefault,
            'chips' => [$managedReady ? ['ok' => true, 'label' => 'Can send'] : ['ok' => false, 'label' => 'Needs attention', 'hint' => 'Verification is not complete yet. Finish it in Text messaging.']],
            'sender' => 'managed', 'manage' => route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $business->uid]), 'manageLabel' => 'Manage number', 'disconnect' => null,
        ];
    }
    $selected = collect($rows)->firstWhere('current', true);
    $others = collect($rows)->where('current', false)->values();
    $chipSvg = fn ($ok) => $ok ? '<path d="M5 12.5l4.5 4.5L19 7.5"/>' : '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/>';
@endphp
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'phone-messaging', 'ogInside' => true])

    {{-- Selected sending number --}}
    @if($selected)
        <section class="card" style="display:flex;flex-wrap:wrap;align-items:center;gap:16px;padding:20px 22px" data-role="selected-sender" aria-labelledby="og-sender">
            <span style="width:50px;height:50px;border-radius:14px;background:var(--og-green-s);color:var(--og-green);display:flex;align-items:center;justify-content:center" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" style="width:24px;height:24px"><path d="M4 5h16v11H9l-5 4z"/></svg></span>
            <div style="flex:1 1 320px;display:flex;flex-direction:column;gap:6px;min-width:0">
                <span class="eyebrow" id="og-sender">Sending from</span>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <b style="font-size:22px;font-weight:800">{{ $selected['number'] }}</b>
                    <span class="pl n">{{ $selected['provider'] }}</span>
                    <span class="pl g">Current sender</span>
                </div>
                <div class="chips">
                    @foreach($selected['chips'] as $chip)
                        <span class="chip {{ $chip['ok'] ? '' : 'no' }}" data-ok="{{ $chip['ok'] ? '1' : '0' }}"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true">{!! $chipSvg($chip['ok']) !!}</svg>{{ $chip['label'] }}</span>
                    @endforeach
                </div>
                @foreach($selected['chips'] as $chip)
                    @if(! $chip['ok'] && ! empty($chip['hint']))<span class="mut">{{ $chip['hint'] }}</span>@endif
                @endforeach
            </div>
            <a class="btn out" href="{{ $selected['manage'] }}">{{ $selected['manageLabel'] }}</a>
        </section>
    @else
        <section class="ready no" data-role="no-sender">
            <span class="bubble" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24" style="width:24px;height:24px"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/></svg></span>
            <div class="body"><b>No sending number selected</b><span class="mut">Connect your own Telnyx number or get one through us below, then choose it as your sender. Nothing can be texted until you do.</span></div>
        </section>
    @endif

    {{-- Carrier registration is never claimed as verified. --}}
    <div class="note" data-role="carrier-warning"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/></svg>
        <span>Carrier registration is not checked here. In the US, texting prospects normally needs your 10DLC brand and campaign (or a verified toll-free number) approved in Telnyx. If Telnyx declines a message, it shows as a failed send — nothing is charged by MotionGrove.</span></div>

    @if($others->isNotEmpty())
        <span class="eyebrow" style="margin-top:6px">Your other numbers</span>
        <section class="card" data-role="other-senders">
            @foreach($others as $row)
                <div class="row rsp" style="grid-template-columns:minmax(0,1.2fr) minmax(0,1.6fr) auto">
                    <div style="display:flex;flex-direction:column;min-width:0"><b>{{ $row['number'] }}</b><span class="mut">{{ $row['provider'] }}</span></div>
                    <div style="display:flex;flex-direction:column;gap:4px">
                        <div class="chips">
                            @foreach($row['chips'] as $chip)
                                <span class="chip {{ $chip['ok'] ? '' : 'no' }}"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true">{!! $chipSvg($chip['ok']) !!}</svg>{{ $chip['label'] }}</span>
                            @endforeach
                        </div>
                        @foreach($row['chips'] as $chip)@if(! $chip['ok'] && ! empty($chip['hint']))<span class="mut">{{ $chip['hint'] }}</span>@endif @endforeach
                    </div>
                    <div class="acts" style="flex-wrap:wrap">
                        <form method="post" action="{{ route('customer.workspaces.prospecting.phone-messaging.sender', $workspaceUid) }}">@csrf
                            <input type="hidden" name="sender" value="{{ $row['sender'] }}">
                            <button type="submit" class="btn pri sm">Use as my sender</button></form>
                        <a class="btn out sm" href="{{ $row['manage'] }}">{{ $row['kind'] === 'byo' ? 'Manage / change account' : $row['manageLabel'] }}</a>
                        @if($row['disconnect'])
                            <form method="post" action="{{ $row['disconnect'] }}" onsubmit="return confirm('Disconnect this Telnyx account? Its saved credentials will be erased. Your Telnyx account and number are not changed.');">@csrf
                                <button type="submit" class="btn out sm">Disconnect</button></form>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    <span class="eyebrow" style="margin-top:6px">Other ways to send</span>
    <div class="grid2" style="align-items:stretch">
        <section class="card" style="flex:1 1 320px;padding:18px 20px;display:flex;flex-direction:column;gap:8px" data-role="option-a">
            <span class="hint" style="font-weight:700;letter-spacing:.06em;text-transform:uppercase">Option A</span>
            <b style="font-size:15px">{{ $telnyx->isEmpty() ? 'Connect your Telnyx account' : 'Add another Telnyx number' }}</b>
            <span style="font-size:13px;color:var(--og-neutral);flex:1">Billed by Telnyx; nothing is taken from your MotionGrove balance. Your key is checked with Telnyx before it is saved, then never shown again.</span>
            <a class="btn out" href="{{ route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, 'Telnyx']) }}">{{ $telnyx->isEmpty() ? 'Connect a Telnyx number' : 'Connect a number' }}</a>
        </section>
        <section class="card" style="flex:1 1 320px;padding:18px 20px;display:flex;flex-direction:column;gap:8px" data-role="option-b">
            <span class="hint" style="font-weight:700;letter-spacing:.06em;text-transform:uppercase">Option B</span>
            <b style="font-size:15px">Get a number through MotionGrove</b>
            @if($business === null)
                <span style="font-size:13px;color:var(--og-neutral);flex:1">Your agency needs exactly one active business account of its own before it can get a number.</span>
                <span class="btn out dis" aria-disabled="true">Get a number</span>
            @else
                <span style="font-size:13px;color:var(--og-neutral);flex:1">Paid from your agency's own MotionGrove usage wallet. Number search, verification and purchase use the same flow as every business account.</span>
                <a class="btn out" href="{{ route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $business->uid]) }}">{{ $hasManagedNumber ? 'Manage number' : 'Get a number' }}</a>
            @endif
        </section>
    </div>
    <span class="hint">Numbers here belong to your agency, never a client. Changing the sender only affects new messages — past conversations stay as they are.</span>
</div>
@endsection
