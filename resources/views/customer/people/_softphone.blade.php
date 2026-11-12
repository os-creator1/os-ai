{{-- CRM browser softphone: see public/js/crm/mg-softphone.js. Rendered only for a user who may place calls. --}}
@php
    $softphoneConfig = [
        'csrf' => csrf_token(),
        'contactUid' => $contactUid,
        'contactLabel' => $displayName,
        'sessionUrl' => $browserCalling['sessionUrl'],
        'placeUrl' => $browserCalling['placeUrl'],
        'statusUrl' => $browserCalling['statusUrl'],
        'hangupUrl' => $browserCalling['hangupUrl'],
    ];
@endphp
<div id="mg-softphone" class="mg-softphone" hidden data-state="idle" data-config="{{ json_encode($softphoneConfig) }}" role="dialog" aria-label="Call {{ $displayName }}" aria-live="polite">
    <div class="mg-softphone-head">
        <span class="mg-softphone-dot" aria-hidden="true"></span>
        <strong class="mg-softphone-status" data-role="softphone-status"></strong>
        <span class="mg-softphone-timer text-numeric" data-role="softphone-timer" hidden>0:00</span>
    </div>
    <p class="mg-softphone-who">{{ $displayName }}</p>
    <p class="mg-softphone-detail" data-role="softphone-detail"></p>
    <div class="mg-softphone-actions">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-role="softphone-mute" data-label-mute="Mute" data-label-unmute="Unmute" aria-pressed="false" hidden>Mute</button>
        <button type="button" class="btn btn-danger btn-sm" data-role="softphone-hangup" hidden>Hang up</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-role="softphone-close" hidden>Close</button>
    </div>
</div>
<audio id="mg-remote-audio" autoplay playsinline></audio>
<script src="{{ asset('vendor/telnyx/webrtc-2.28.2.js') }}"></script>
<script src="{{ asset('js/crm/mg-softphone.js') }}"></script>
