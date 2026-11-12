{{-- CRM browser softphone: see public/js/crm/mg-softphone.js. Rendered only for a user who may place calls.
     Visual design: the 340px floating call popup. Controls for functions that do not exist yet (keypad, hold, note,
     recording, transfer, incoming answer) are rendered `hidden` with data-capability hooks and stay hidden until the
     script's `capabilities` flags are switched on by a real implementation. --}}
@php
    $spName = (string) $displayName;
    $spWords = preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $spName)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $spInitials = $spWords === [] ? '#' : mb_strtoupper(mb_substr($spWords[0], 0, 1).(count($spWords) > 1 ? mb_substr(end($spWords), 0, 1) : ''));
    $spPhone = (string) ($profile['phone'] ?? '');
    $softphoneConfig = [
        'csrf' => csrf_token(),
        'contactUid' => $contactUid,
        'contactLabel' => $displayName,
        'contactPhone' => $spPhone,
        'contactInitials' => $spInitials,
        'sessionUrl' => $browserCalling['sessionUrl'],
        'placeUrl' => $browserCalling['placeUrl'],
        'statusUrl' => $browserCalling['statusUrl'],
        'hangupUrl' => $browserCalling['hangupUrl'],
    ];
@endphp
<style>
    .mg-softphone{--sp-bg:#fff;--sp-fg:#1f1a19;--sp-fg2:#2e2826;--sp-muted:#6b625e;--sp-line:#e9e4df;--sp-line2:#d6cfc8;--sp-chip:#f4f0ec;--sp-chip-h:#ece6e0;--sp-av-bg:#f8e9e6;--sp-av-fg:#8f3b35;--sp-end:#b4514a;--sp-amber:#d9a441;--sp-green:#1f8a55;--sp-grey:#8a817c;--sp-pad:#f6f4f1;--sp-warn-bg:#fdf6e3;--sp-warn-fg:#7a4a05;--sp-err-bg:#fbf0ee;--sp-err-fg:#8f3b35;--sp-ok-bg:#e3f3ea;--sp-ok-fg:#17693f;position:fixed;right:1.25rem;bottom:1.25rem;z-index:1080;width:340px;max-width:calc(100vw - 1.5rem);box-sizing:border-box;background:var(--sp-bg);color:var(--sp-fg);border:1px solid var(--sp-line);border-radius:18px;box-shadow:0 18px 48px rgba(31,26,25,.18),0 2px 6px rgba(31,26,25,.06);overflow:hidden;font-family:'Figtree',system-ui,-apple-system,'Segoe UI',sans-serif}
    html.dark-layout .mg-softphone{--sp-bg:#262625;--sp-fg:#f3f4f6;--sp-fg2:#e7e5e1;--sp-muted:#a8a39d;--sp-line:#3d3c39;--sp-line2:#4a4946;--sp-chip:#32312f;--sp-chip-h:#3d3c39;--sp-av-bg:#3b2623;--sp-av-fg:#f0b4ae;--sp-pad:#2f2e2c;--sp-warn-bg:#3a3020;--sp-warn-fg:#f0cf8c;--sp-err-bg:#3b2623;--sp-err-fg:#f0b4ae;--sp-ok-bg:#1d3a2a;--sp-ok-fg:#8fd9b0;box-shadow:0 18px 48px rgba(0,0,0,.5),0 2px 6px rgba(0,0,0,.3)}
    .mg-softphone[hidden],.mg-softphone [hidden]{display:none!important}
    .mg-softphone .mg-sp-ic{width:16px;height:16px;flex:none;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
    .mg-sp-top{display:flex;align-items:center;gap:8px;padding:12px 12px 0 18px;font-size:13px;font-weight:600}
    .mg-sp-dot{width:8px;height:8px;border-radius:50%;flex:none;background:var(--sp-grey)}
    .mg-softphone[data-state="connecting"] .mg-sp-dot,.mg-softphone[data-state="ringing"] .mg-sp-dot{background:var(--sp-amber);animation:mg-sp-pl 1.4s ease-in-out infinite}
    .mg-softphone[data-state="connected"] .mg-sp-dot{background:var(--sp-green)}
    .mg-softphone[data-state="failed"] .mg-sp-dot{background:var(--sp-end)}
    @keyframes mg-sp-pl{0%,100%{opacity:1}50%{opacity:.3}}
    @media (prefers-reduced-motion:reduce){.mg-softphone .mg-sp-dot{animation:none!important}}
    .mg-sp-title{flex:1;min-width:0}
    .mg-sp-timer{font-variant-numeric:tabular-nums;color:var(--sp-fg2)}
    .mg-sp-ib{width:32px;height:32px;border-radius:8px;border:0;background:transparent;color:var(--sp-muted);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0}
    .mg-sp-ib:hover{background:var(--sp-chip)}
    .mg-sp-who{display:flex;align-items:center;gap:12px;padding:12px 18px 16px}
    .mg-sp-av{width:44px;height:44px;border-radius:50%;background:var(--sp-av-bg);color:var(--sp-av-fg);display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;flex:none}
    .mg-sp-nm{display:flex;flex-direction:column;gap:1px;min-width:0}
    .mg-sp-nm b{font-size:17px;font-weight:700;overflow-wrap:anywhere}
    .mg-sp-nm span{font-size:13px;color:var(--sp-muted);font-variant-numeric:tabular-nums}
    .mg-sp-note{margin:0 18px 14px;padding:10px 12px;border-radius:10px;background:var(--sp-chip);font-size:12.5px;font-weight:500;color:var(--sp-fg2);line-height:1.4}
    .mg-softphone[data-state="failed"] .mg-sp-note{background:var(--sp-err-bg);color:var(--sp-err-fg)}
    .mg-sp-ctl{display:flex;flex-wrap:wrap;justify-content:center;gap:12px 28px;padding:4px 18px 16px}
    .mg-sp-k{display:flex;flex-direction:column;align-items:center;gap:6px;border:0;background:transparent;font:inherit;font-size:12px;font-weight:500;color:var(--sp-fg2);padding:0;cursor:pointer}
    .mg-sp-k i{width:52px;height:52px;border-radius:50%;background:var(--sp-chip);color:var(--sp-fg2);display:flex;align-items:center;justify-content:center;font-style:normal}
    .mg-sp-k:hover i{background:var(--sp-chip-h)}
    .mg-sp-k.on i{background:var(--sp-fg);color:var(--sp-bg)}
    .mg-sp-k[disabled]{opacity:.4;cursor:not-allowed}
    .mg-sp-k svg{width:20px;height:20px}
    .mg-sp-bot{display:flex;gap:8px;padding:0 18px 18px}
    .mg-sp-end{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;border:0;border-radius:12px;background:var(--sp-end);color:#fff;font:inherit;font-size:14.5px;font-weight:600;cursor:pointer}
    .mg-sp-end svg{width:18px;height:18px}
    .mg-sp-acc{background:var(--sp-green)}
    .mg-sp-sec{flex:1;display:inline-flex;align-items:center;justify-content:center;min-height:44px;border:1px solid var(--sp-line2);border-radius:12px;background:var(--sp-bg);color:var(--sp-fg2);font:inherit;font-size:14px;font-weight:600;cursor:pointer}
    .mg-sp-sec:hover{background:var(--sp-chip)}
    .mg-sp-end:focus-visible,.mg-sp-sec:focus-visible,.mg-sp-k:focus-visible,.mg-sp-ib:focus-visible,.mg-sp-pill button:focus-visible{outline:2px solid var(--sp-end);outline-offset:2px}
    .mg-sp-lab{font-size:12px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--sp-muted)}
    .mg-sp-sum{padding:0 18px 14px;display:flex;flex-direction:column;gap:2px}
    .mg-sp-row{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:40px;font-size:14px;border-top:1px solid var(--sp-line)}
    .mg-sp-row:first-of-type{border-top:0}
    .mg-sp-row span:first-child{color:var(--sp-muted)}
    .mg-sp-row b{font-weight:600;font-variant-numeric:tabular-nums;text-align:right}
    .mg-sp-out{display:inline-flex;align-items:center;height:26px;padding:0 10px;border-radius:999px;font-size:12.5px;font-weight:700;background:var(--sp-ok-bg);color:var(--sp-ok-fg)}
    .mg-sp-out[data-outcome="unanswered"]{background:var(--sp-warn-bg);color:var(--sp-warn-fg)}
    .mg-sp-out[data-outcome="failed"]{background:var(--sp-err-bg);color:var(--sp-err-fg)}
    .mg-sp-pad{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:0 26px 14px}
    .mg-sp-pad button{height:50px;border-radius:12px;border:0;background:var(--sp-pad);display:flex;flex-direction:column;align-items:center;justify-content:center;font:inherit;font-size:20px;font-weight:600;color:var(--sp-fg);line-height:1;cursor:pointer}
    .mg-sp-pad small{font-size:9.5px;font-weight:600;letter-spacing:.12em;color:var(--sp-grey);margin-top:2px}
    .mg-sp-digits{padding:10px 18px 12px;text-align:center;font-size:26px;font-weight:700;letter-spacing:.12em;font-variant-numeric:tabular-nums;min-height:34px}
    .mg-sp-field{margin:0 18px 14px;display:flex;flex-direction:column;gap:8px}
    .mg-sp-field textarea{min-height:84px;padding:10px 12px;border:1px solid var(--sp-end);border-radius:10px;font:inherit;font-size:14px;line-height:1.45;background:var(--sp-bg);color:var(--sp-fg);resize:vertical;box-shadow:0 0 0 3px var(--sp-av-bg)}
    .mg-sp-pill{position:fixed;right:1.25rem;bottom:1.25rem;z-index:1080;display:inline-flex;align-items:center;gap:10px;height:56px;padding:0 8px 0 10px;border-radius:999px;background:#1f1a19;color:#fff;box-shadow:0 12px 32px rgba(31,26,25,.25);font-size:14px;font-family:'Figtree',system-ui,-apple-system,'Segoe UI',sans-serif;max-width:calc(100vw - 1.5rem);box-sizing:border-box}
    .mg-sp-pill[hidden]{display:none!important}
    html.dark-layout .mg-sp-pill{background:#3d3c39;box-shadow:0 12px 32px rgba(0,0,0,.5)}
    .mg-sp-pill .mg-sp-av{width:36px;height:36px;background:#fff;color:#8f3b35;font-size:12.5px}
    .mg-sp-pill .mg-sp-pn{display:flex;flex-direction:column;line-height:1.2;min-width:0}
    .mg-sp-pill .mg-sp-pn b{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:10rem}
    .mg-sp-pill .mg-sp-pn span{font-size:12px;color:#bdb4ae;font-variant-numeric:tabular-nums;display:flex;align-items:center;gap:5px}
    .mg-sp-pill .mg-sp-pn .mg-sp-dot{width:7px;height:7px;background:#3fbf7f;animation:none}
    .mg-sp-pill button{width:40px;height:40px;border-radius:50%;border:0;background:rgba(255,255,255,.12);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0}
    .mg-sp-pill button.mg-sp-pend{background:var(--sp-end,#b4514a)}
    .mg-sp-pill button.on{background:#fff;color:#1f1a19}
    @media (max-width:480px){.mg-softphone{right:.75rem;left:.75rem;bottom:.75rem;width:auto;max-width:none}.mg-sp-pill{right:.75rem;bottom:.75rem}}
</style>
<div id="mg-softphone" class="mg-softphone" hidden data-state="idle" data-config="{{ json_encode($softphoneConfig) }}" role="dialog" aria-label="Call {{ $displayName }}" aria-live="polite">
    <div class="mg-sp-top">
        <span class="mg-sp-dot" aria-hidden="true"></span>
        <span class="mg-sp-title" data-role="softphone-status"></span>
        <span class="mg-sp-timer" data-role="softphone-timer" hidden>00:00</span>
        <button type="button" class="mg-sp-ib" data-role="softphone-minimize" aria-label="Minimize" hidden><svg class="mg-sp-ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
        <button type="button" class="mg-sp-ib" data-role="softphone-close" aria-label="Close" hidden><svg class="mg-sp-ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    </div>
    <div class="mg-sp-who">
        <span class="mg-sp-av" aria-hidden="true">{{ $spInitials }}</span>
        <div class="mg-sp-nm">
            <b>{{ $displayName }}</b>
            <span data-role="softphone-sub">{{ $spPhone }}</span>
        </div>
    </div>
    <p class="mg-sp-note" data-role="softphone-detail" hidden></p>

    {{-- Live-call controls. Only Mute is functional; the rest are hooks for later work. --}}
    <div class="mg-sp-ctl" data-role="softphone-controls" hidden>
        <button type="button" class="mg-sp-k" data-role="softphone-mute" data-label-mute="Mute" data-label-unmute="Unmute" aria-pressed="false"><i><svg class="mg-sp-ic" viewBox="0 0 24 24"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21"/></svg></i><span data-role="softphone-mute-label">Mute</span></button>
        <button type="button" class="mg-sp-k" data-role="softphone-keypad" data-capability="keypad" hidden><i><svg class="mg-sp-ic" viewBox="0 0 24 24"><circle cx="6" cy="5" r="1.3"/><circle cx="12" cy="5" r="1.3"/><circle cx="18" cy="5" r="1.3"/><circle cx="6" cy="11" r="1.3"/><circle cx="12" cy="11" r="1.3"/><circle cx="18" cy="11" r="1.3"/><circle cx="6" cy="17" r="1.3"/><circle cx="12" cy="17" r="1.3"/><circle cx="18" cy="17" r="1.3"/><circle cx="12" cy="22" r=".6"/></svg></i>Keypad</button>
        <button type="button" class="mg-sp-k" data-role="softphone-hold" data-capability="hold" hidden><i><svg class="mg-sp-ic" viewBox="0 0 24 24" style="stroke-width:2.4"><path d="M9 5v14M15 5v14"/></svg></i>Hold</button>
        <button type="button" class="mg-sp-k" data-role="softphone-record" data-capability="record" hidden><i><svg class="mg-sp-ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5" fill="currentColor"/></svg></i>Record</button>
        <button type="button" class="mg-sp-k" data-role="softphone-transfer" data-capability="transfer" hidden><i><svg class="mg-sp-ic" viewBox="0 0 24 24"><path d="M4 9h13l-3.5-3.5M20 15H7l3.5 3.5"/></svg></i>Transfer</button>
        <button type="button" class="mg-sp-k" data-role="softphone-notebtn" data-capability="note" hidden><i><svg class="mg-sp-ic" viewBox="0 0 24 24"><path d="M5 4h10l4 4v12H5zM9 12h6M9 16h4"/></svg></i>Note</button>
    </div>

    {{-- Hook: keypad view (not enabled; no DTMF path is wired). --}}
    <div data-role="softphone-keypad-view" hidden>
        <div class="mg-sp-digits" data-role="softphone-digits"></div>
        <div class="mg-sp-pad">
            @foreach ([['1',' '],['2','ABC'],['3','DEF'],['4','GHI'],['5','JKL'],['6','MNO'],['7','PQRS'],['8','TUV'],['9','WXYZ'],['*',' '],['0','+'],['#',' ']] as [$k, $l])
                <button type="button" data-digit="{{ $k }}">{{ $k }}<small>{{ trim($l) === '' ? "\u{00a0}" : $l }}</small></button>
            @endforeach
        </div>
    </div>
    {{-- Hook: hold banner and in-call note (not enabled; nothing is saved by them). --}}
    <div class="mg-sp-note" data-role="softphone-hold-banner" hidden>They hear hold music.</div>
    <div class="mg-sp-field" data-role="softphone-note-view" hidden><span class="mg-sp-lab">Note</span><textarea data-role="softphone-note-text" aria-label="Call note"></textarea><span style="font-size:12px;color:var(--sp-muted)">Saves to the contact when the call ends.</span></div>

    {{-- Post-call summary. --}}
    <div class="mg-sp-sum" data-role="softphone-summary" hidden>
        <div class="mg-sp-row"><span>Outcome</span><span class="mg-sp-out" data-role="summary-outcome">Connected</span></div>
        <div class="mg-sp-row"><span>Duration</span><b data-role="summary-duration">00:00</b></div>
        <div class="mg-sp-row"><span>Direction</span><b data-role="summary-direction">Outgoing</b></div>
        <div class="mg-sp-row"><span>Saved</span><b>To this contact</b></div>
    </div>

    {{-- Hook: incoming call (not enabled; inbound browser calls are not supported yet). --}}
    <div class="mg-sp-bot" data-role="softphone-incoming" data-capability="incoming" hidden>
        <button type="button" class="mg-sp-sec" data-role="softphone-decline" style="color:var(--sp-err-fg)">Decline</button>
        <button type="button" class="mg-sp-end mg-sp-acc" data-role="softphone-answer"><svg class="mg-sp-ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1z"/></svg>Answer</button>
    </div>

    <div class="mg-sp-bot" data-role="softphone-hangup-row" hidden>
        <button type="button" class="mg-sp-end" data-role="softphone-hangup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14.5c5-5 13-5 18 0l-2.5 2.5-3.5-1.5v-2.5a10 10 0 0 0-6 0V15.5L5.5 17z"/></svg><span data-role="softphone-hangup-label">End call</span></button>
    </div>
    <div class="mg-sp-bot" data-role="softphone-done-row" hidden>
        <button type="button" class="mg-sp-sec" data-role="softphone-done">Close</button>
        <button type="button" class="mg-sp-end" data-role="softphone-again">Call again</button>
    </div>
</div>

{{-- Minimized call pill --}}
<div class="mg-sp-pill" data-role="softphone-pill" hidden role="group" aria-label="Call in progress">
    <span class="mg-sp-av" aria-hidden="true">{{ $spInitials }}</span>
    <span class="mg-sp-pn"><b>{{ $displayName }}</b><span><i class="mg-sp-dot" aria-hidden="true" style="display:inline-block"></i><span data-role="pill-timer">00:00</span></span></span>
    <button type="button" data-role="pill-mute" aria-label="Mute" aria-pressed="false" style="margin-left:8px"><svg class="mg-sp-ic" viewBox="0 0 24 24"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21"/></svg></button>
    <button type="button" data-role="pill-expand" aria-label="Expand"><svg class="mg-sp-ic" viewBox="0 0 24 24"><path d="M14 4h6v6M10 20H4v-6M20 4l-6 6M4 20l6-6"/></svg></button>
    <button type="button" class="mg-sp-pend" data-role="pill-hangup" aria-label="End call"><span style="width:18px;height:18px;display:flex"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14.5c5-5 13-5 18 0l-2.5 2.5-3.5-1.5v-2.5a10 10 0 0 0-6 0V15.5L5.5 17z"/></svg></span></button>
</div>
<audio id="mg-remote-audio" autoplay playsinline></audio>
<script src="{{ asset('vendor/telnyx/webrtc-2.28.2.js') }}"></script>
<script src="{{ asset('js/crm/mg-softphone.js') }}"></script>
