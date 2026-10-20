{{--
    Browser calling (Telnyx WebRTC) — the ONE floating phone panel, mounted once per page by the shared layout.
    Renders only inside a Business route for a signed-in user who is not "viewing as" a client, and only
    when calling could be available (platform voice on, or this Business has a BYO voice number). It
    carries no credential: the page learns its tokens from authorized, no-store JSON calls at run time.
    Call buttons (Contacts, Conversations) carry [data-mg-call]; softphone.js wires them all to this panel.
--}}
@php
    $voiceWorkspaceUid = request()->route('workspaceUid');
    $voiceBusinessUid = request()->route('businessUid');
    $voiceShow = \App\Library\Voice\VoiceAvailability::mounted(request());

    $voiceBase = $voiceShow ? route('customer.workspaces.businesses.voice.options', [$voiceWorkspaceUid, $voiceBusinessUid]) : null;
    $voiceBase = $voiceBase !== null ? preg_replace('#/options$#', '', $voiceBase) : null;
    $voicePrefix = $voiceBase !== null ? preg_replace('#voice$#', '', (string) parse_url($voiceBase, PHP_URL_PATH)) : '/';
    $voiceStamp = fn (string $path) => asset($path) . '?v=' . (@filemtime(public_path($path)) ?: 1);
@endphp

@if ($voiceShow)
    <style>
        .mg-voice{position:fixed;z-index:1090;right:1.25rem;bottom:1.25rem;width:min(21rem,calc(100vw - 2rem));font-size:.9rem;color:var(--color-text-primary,#2b2a27)}
        .mg-voice[hidden]{display:none}
        .mg-voice-card{display:none;background:var(--color-surface-primary,#fff);border:1px solid var(--color-border-neutral,#e5e1da);border-radius:1rem;box-shadow:0 12px 32px rgba(40,36,30,.18);padding:1rem 1.1rem}
        .mg-voice.is-open .mg-voice-card{display:block}
        .mg-voice-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem}
        .mg-voice-badge{font-size:.7rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted,#6f6d67)}
        .mg-voice-x{border:0;background:transparent;font-size:1.3rem;line-height:1;color:var(--color-text-muted,#6f6d67);cursor:pointer;padding:0 .25rem}
        .mg-voice-name{font-weight:700;font-size:1.05rem;overflow-wrap:anywhere}
        .mg-voice-num,.mg-voice-from{color:var(--color-text-muted,#6f6d67);font-size:.8rem}
        .mg-voice-timer{margin:.5rem 0 0;font-size:1.6rem;font-weight:600;font-variant-numeric:tabular-nums}
        .mg-voice-msg{margin:.5rem 0 0;min-height:1.1em;color:var(--color-text-muted,#6f6d67)}
        .mg-voice-msg.is-error{color:var(--color-danger,#b3261e)}
        .mg-voice-pick label,.mg-voice-note label{display:block;margin-top:.6rem;font-size:.8rem;font-weight:500}
        .mg-voice-pick select,.mg-voice-note textarea{display:block;width:100%;margin-top:.25rem;border:1px solid var(--color-border-neutral,#e5e1da);border-radius:.5rem;padding:.4rem .5rem;background:var(--color-surface-primary,#fff);color:inherit}
        .mg-voice-actions{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.9rem}
        .mg-voice-btn{flex:1 1 auto;min-height:2.5rem;border:1px solid var(--color-border-neutral,#e5e1da);border-radius:.6rem;background:var(--color-surface-primary,#fff);color:inherit;font-weight:600;padding:.4rem .9rem;cursor:pointer}
        .mg-voice-btn:focus-visible,.mg-voice-x:focus-visible{outline:2px solid var(--color-primary,#b5524c);outline-offset:2px}
        .mg-voice-btn[disabled]{opacity:.55;cursor:not-allowed}
        .mg-voice-btn.is-on{background:#efece7}
        .mg-voice-ok{background:#1f7a4d;border-color:#1f7a4d;color:#fff}
        .mg-voice-bad{background:var(--color-danger,#b3261e);border-color:var(--color-danger,#b3261e);color:#fff}
        .mg-voice-link{background:none;border:0;color:var(--color-primary,#b5524c);font-weight:600;text-decoration:underline;cursor:pointer;padding:0 0 0 .35rem}
        .mg-voice-incoming .mg-voice-card{border-color:#1f7a4d;box-shadow:0 0 0 3px rgba(31,122,77,.25),0 12px 32px rgba(40,36,30,.22)}
        .mg-voice-strip{margin-top:.5rem;background:var(--color-surface-primary,#fff);border:1px solid var(--color-border-neutral,#e5e1da);border-radius:.7rem;padding:.5rem .75rem;font-size:.8rem}
        .mg-voice-reason{margin-top:.35rem;font-size:.8rem;color:var(--color-text-muted,#6f6d67)}
        [data-mg-call][disabled]{opacity:.6;cursor:not-allowed}
        @media (max-width:575.98px){.mg-voice{right:0;left:0;bottom:0;width:auto}.mg-voice-card{border-radius:1rem 1rem 0 0;padding-bottom:calc(1rem + env(safe-area-inset-bottom))}.mg-voice-btn{min-height:2.9rem}}
        @media (prefers-reduced-motion:no-preference){.mg-voice-incoming .mg-voice-badge{animation:mgvoicepulse 1.2s ease-in-out infinite}@keyframes mgvoicepulse{50%{opacity:.35}}}
    </style>
    <script src="{{ $voiceStamp('js/voice/softphone-core.js') }}"></script>
    <script src="{{ $voiceStamp('js/voice/softphone.js') }}"></script>
    <script>
        window.MGSoftphone.boot({
            workspaceUid: @json($voiceWorkspaceUid),
            businessUid: @json($voiceBusinessUid),
            businessPrefix: @json($voicePrefix),
            csrf: @json(csrf_token()),
            sdkUrl: @json($voiceStamp('vendor/telnyx/telnyx-webrtc-2.27.10.js')),
            urls: {
                session: @json($voiceBase . '/session'),
                options: @json($voiceBase . '/options'),
                lookup: @json($voiceBase . '/lookup'),
                token: @json($voiceBase . '/token'),
                heartbeat: @json($voiceBase . '/heartbeat'),
                offline: @json($voiceBase . '/offline'),
                calls: @json($voiceBase . '/calls'),
                callBase: @json($voiceBase . '/calls')
            }
        });
    </script>
@endif
