<fieldset data-role="sign-form">
            <legend>Sign this document</legend>

            @foreach($formErrors as $messages)
                @foreach($messages as $message)
                    <p class="error" data-role="sign-error">{{ $message }}</p>
                @endforeach
            @endforeach

            <form method="POST" action="{{ route('public.documents.sign', ['uid' => $document->uid, 'token' => request()->route('token')]) }}">
                @csrf
                {{-- Which version this page was rendered from; the server refuses
                     the signature if the current version is a different one. --}}
                <input type="hidden" name="displayed_version_uid" value="{{ $version->uid }}">
                <label for="signer_name">Your full name</label>
                <input id="signer_name" type="text" name="signer_name" maxlength="160" value="{{ old('signer_name') }}" required>

                <label for="signer_email">Your email address</label>
                <input id="signer_email" type="email" name="signer_email" maxlength="255" value="{{ old('signer_email') }}" required>

                <label for="typed_name">Type your name to sign</label>
                <input id="typed_name" type="text" name="typed_name" maxlength="160" value="{{ old('typed_name') }}" required>

                <p class="consent" data-role="consent-statement">{{ $consentStatement }}</p>

                <button type="submit">Sign document</button>
            </form>
        </fieldset>
