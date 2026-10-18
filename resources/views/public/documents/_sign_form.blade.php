<fieldset class="pd-sign" id="sign" data-role="sign-form">
            <legend>Sign this document</legend>

            @foreach($formErrors as $messages)
                @foreach($messages as $message)
                    <p class="error" data-role="sign-error">{{ $message }}</p>
                @endforeach
            @endforeach

            <form method="POST" data-role="sign-form-element" action="{{ route('public.documents.sign', ['uid' => $document->uid, 'token' => request()->route('token')]) }}">
                @csrf
                {{-- Which version this page was rendered from; the server refuses
                     the signature if the current version is a different one. --}}
                <input type="hidden" name="displayed_version_uid" value="{{ $version->uid }}">
                <div class="pd-row">
                    <div>
                        <label for="signer_name">Your full name</label>
                        <input id="signer_name" type="text" name="signer_name" maxlength="160" value="{{ old('signer_name') }}" autocomplete="name" required>
                    </div>
                    <div>
                        <label for="signer_email">Your email address</label>
                        <input id="signer_email" type="email" name="signer_email" maxlength="255" value="{{ old('signer_email') }}" autocomplete="email" required>
                    </div>
                </div>

                <label for="typed_name">Type your name to sign</label>
                <input id="typed_name" type="text" name="typed_name" maxlength="160" value="{{ old('typed_name') }}" placeholder="Your name" autocomplete="off" required>

                {{-- The statement is the server-side constant. The box is a
                     client-side acknowledgement only: it has no name, so it adds
                     nothing to the request and the signing contract is unchanged. --}}
                <label class="consent" for="consent_ack" data-role="consent-statement">
                    <input id="consent_ack" type="checkbox" required>
                    <span>{{ $consentStatement }}</span>
                </label>

                <div class="pd-actions">
                    <button type="submit" data-role="sign-button">Sign document &rarr;</button>
                    @if($schedule->isNotEmpty())<span class="muted">Next: you'll see how to pay.</span>@endif
                </div>
            </form>
        </fieldset>

<script>
    (function () {
        var form = document.querySelector('[data-role="sign-form-element"]');
        if (!form) { return; }
        var button = form.querySelector('[data-role="sign-button"]');
        var label = button.innerHTML;
        var submitting = false;

        // The submit event fires only after the browser's own validation has
        // passed, so an invalid form is never locked. A second click (or Enter)
        // while the first request is in flight is swallowed: one POST, one
        // signature. Server-side validation and idempotency are unchanged.
        form.addEventListener('submit', function (event) {
            if (submitting) { event.preventDefault(); return; }
            submitting = true;
            button.disabled = true;
            button.textContent = 'Signing\u2026';
            button.setAttribute('aria-busy', 'true');
        });

        // Coming back to this page (Back/Forward, or a failed request that left
        // the page active) must never leave a permanently disabled button.
        window.addEventListener('pageshow', function () {
            submitting = false;
            button.disabled = false;
            button.innerHTML = label;
            button.removeAttribute('aria-busy');
        });
    })();
</script>
