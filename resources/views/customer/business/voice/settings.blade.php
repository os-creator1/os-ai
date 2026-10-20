@extends('layouts/contentLayoutMaster')

{{--
    Browser calling setup. A number is only offered for calling once voice is enabled for it HERE and the
    read-only Telnyx check passes — a number that can send texts is never assumed to be able to call.
    Managed numbers use MotionGrove's platform voice connection; an Agency's own Telnyx numbers use the
    Credential Connection the Agency names. Nothing on this page buys, creates or changes anything at Telnyx.
--}}

@section('title', 'Voice calling')

@section('content')
    @php
        $verifyUrl = fn ($uid) => route('customer.workspaces.businesses.voice.settings.numbers.verify', [$workspaceUid, $businessUid, $uid]);
    @endphp

    <section id="voice-settings" class="mg-voice-settings" style="max-width:56rem">
        <h1 class="h3 mb-1">Voice calling</h1>
        <p class="text-muted">Call customers from your browser, and receive calls for one assigned person. Turn voice on per number, then run the check.</p>

        <div class="alert alert-info" role="note">
            <strong>Incoming calls</strong> ring the one person assigned to a number, only while MotionGrove is open in their browser.
            For each number, point a Telnyx <em>Call Control application</em> webhook at:
            <div class="mt-50"><code data-role="managed-webhook">{{ $managedWebhookUrl }}</code> <span class="text-muted">(MotionGrove-managed numbers)</span></div>
            @foreach ($channelWebhooks as $channelId => $url)
                <div class="mt-50"><code>{{ $url }}</code> <span class="text-muted">(your own Telnyx account, connection #{{ $channelId }})</span></div>
            @endforeach
        </div>

        @if (! $managedEnabled && collect($candidates)->contains(fn ($c) => $c['source'] === 'managed'))
            <div class="alert alert-warning" role="alert" data-role="managed-disabled">Managed browser calling is not switched on for this platform yet, so MotionGrove-managed numbers cannot be set up for calling.</div>
        @endif

        @forelse ($candidates as $c)
            @php($voice = $c['voice'])
            <div class="card mb-1" data-role="voice-number" data-source="{{ $c['source'] }}">
                <div class="card-body">
                    <div class="d-flex justify-content-between flex-wrap gap-1">
                        <div>
                            <strong>{{ $c['phone_number'] }}</strong>
                            <span class="badge bg-light text-dark ms-50">{{ $c['source'] === 'byo' ? 'Your Telnyx account' : 'MotionGrove-managed' }}</span>
                        </div>
                        <span data-role="voice-state" class="text-muted">
                            @if ($voice === null) Not set up for calling
                            @elseif ($voice->voice_status === 'ready') Ready for calling
                            @elseif ($voice->voice_status === 'misconfigured') Needs attention: {{ $voice->voice_error_code }}
                            @else Not checked yet @endif
                        </span>
                    </div>

                    <form class="row g-1 mt-50" data-role="voice-form" data-source="{{ $c['source'] }}" data-ref="{{ $c['ref'] }}" @if ($voice) data-uid="{{ $voice->uid }}" @endif>
                        @if ($c['source'] === 'byo')
                            <div class="col-md-6">
                                <label class="form-label">Voice connection ID (Telnyx Credential Connection with WebRTC)</label>
                                <input class="form-control" name="webrtc_connection_id" maxlength="64" value="{{ $voice?->webrtc_connection_id }}" required>
                            </div>
                        @endif
                        <div class="col-md-4">
                            <label class="form-label">Ring for incoming calls</label>
                            <select class="form-select" name="inbound_user_id">
                                <option value="">Nobody (outgoing only)</option>
                                @foreach ($users as $u)
                                    <option value="{{ $u->id }}" @selected($voice && (int) $voice->inbound_user_id === (int) $u->id)>{{ trim($u->first_name . ' ' . $u->last_name) ?: $u->email }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 d-flex gap-1 align-items-center">
                            <button class="btn btn-primary btn-sm" type="submit" data-role="voice-save">{{ $voice ? 'Save' : 'Turn on calling' }}</button>
                            @if ($voice)
                                <button class="btn btn-outline-primary btn-sm" type="button" data-role="voice-verify">Check voice setup</button>
                            @endif
                            <span data-role="voice-result" class="text-muted" role="status"></span>
                        </div>
                    </form>
                </div>
            </div>
        @empty
            <div class="alert alert-secondary" data-role="voice-empty">No phone number is connected to this account yet. Get a number or connect your Telnyx account first, then come back.</div>
        @endforelse
    </section>
@endsection

@section('page-script')
    <script>
        (function () {
            var csrf = @json(csrf_token());
            var saveUrl = @json(route('customer.workspaces.businesses.voice.settings.numbers.save', [$workspaceUid, $businessUid]));
            var verifyBase = @json(preg_replace('#/__UID__/verify$#', '', $verifyUrl('__UID__')));

            function post(url, body) {
                return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body || {}) })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); });
            }

            document.querySelectorAll('[data-role=voice-form]').forEach(function (form) {
                var out = form.querySelector('[data-role=voice-result]');
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var data = { source: form.dataset.source, ref: Number(form.dataset.ref), webrtc_connection_id: (form.elements.webrtc_connection_id || {}).value || null, inbound_user_id: form.elements.inbound_user_id.value ? Number(form.elements.inbound_user_id.value) : null };
                    out.textContent = 'Saving…';
                    post(saveUrl, data).then(function (r) { out.textContent = r.ok ? 'Saved. Now run the check.' : (r.json.message || 'Could not save.'); if (r.ok) { window.location.reload(); } });
                });
                var verify = form.querySelector('[data-role=voice-verify]');
                if (verify) {
                    verify.addEventListener('click', function () {
                        out.textContent = 'Checking with Telnyx…';
                        post(verifyBase.replace(/\/$/, '') + '/' + form.dataset.uid + '/verify', {}).then(function (r) {
                            out.textContent = (r.json.message || '') + ' ' + ((r.json.notes || []).join(' '));
                        });
                    });
                }
            });
        })();
    </script>
@endsection
