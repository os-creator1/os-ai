@extends('layouts/contentLayoutMaster')

@section('title', 'Connect ' . $providerLabel)

@php
    $isTelnyx = ! empty($webhookUrl);
    $secretFields = ['api_key', 'auth_token', 'webhook_public_key'];
    $help = [
        'api_key' => 'Telnyx portal → Auth → API Keys → Create API Key. Copy it when it is shown.',
        'c1' => 'Telnyx portal → Messaging → Messaging Profiles. Open your profile and copy its ID (a UUID).',
        'c2' => 'Optional. Leave blank unless your Telnyx profile uses a specific connection.',
        'webhook_public_key' => 'Telnyx portal → Auth → Public Key. Needed so replies are accepted only when Telnyx signed them.',
    ];
@endphp

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Connect {{ $providerLabel }}</h4>
            <x-button variant="outline" size="sm" icon="arrow-left"
                      :href="route('customer.workspaces.prospecting.channels.index', $workspaceUid)">
                Back
            </x-button>
        </div>
    </div>

    @if($isTelnyx)
        <x-card title="Your webhook URL" :padded="true" class="mb-2">
            <p class="mb-1">Telnyx needs this address when you create your Messaging Profile. It is unique to your workspace and stays the same after you connect, so you can copy it now.</p>
            <div class="input-group mb-1">
                <input type="text" id="webhook-url" class="form-control" readonly value="{{ $webhookUrl }}" onclick="this.select()">
                <button type="button" class="btn btn-outline-primary" id="copy-webhook-url">Copy Webhook URL</button>
            </div>
            <div class="text-caption" id="copy-webhook-status" role="status"></div>
            @error('setup_uid')
                <div class="text-danger mb-1">{{ $message }}</div>
            @enderror
            <div class="text-caption">Nothing is sent or received until you finish connecting below. Replies are accepted only when signed with your Telnyx webhook public key.</div>
        </x-card>

        <x-card title="Setup steps" :padded="true" class="mb-2">
            <ol class="mb-0">
                <li>In Telnyx, create an API key (Auth → API Keys).</li>
                <li>Copy your webhook URL above.</li>
                <li>In Telnyx, go to Messaging → Messaging Profiles, create a profile, and paste the URL as its inbound webhook URL. Assign your phone number to the profile.</li>
                <li>Copy the Messaging Profile ID from Telnyx into the form below.</li>
                <li>Enter your sender number (the Telnyx number on that profile) and your Telnyx webhook public key (Auth → Public Key).</li>
                <li>Select Connect. We verify the key, profile and number with Telnyx, then you choose the number to send from on the next page.</li>
            </ol>
        </x-card>
    @endif

    <x-card title="{{ $isTelnyx ? 'Your Telnyx details' : 'Account details' }}" :padded="true">
        <form method="post" action="{{ route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, $provider]) }}">
            @csrf
            @if($isTelnyx)
                <input type="hidden" name="setup_uid" value="{{ $setupUid }}">
            @endif

            <div class="mb-1">
                <label class="form-label" for="sender_number">Sender number <span class="text-danger">*</span> <small class="text-muted">(include country code)</small></label>
                <input type="text" id="sender_number" name="sender_number"
                       class="form-control @error('sender_number') is-invalid @enderror"
                       placeholder="+1 555 123 4567" value="{{ old('sender_number') }}">
                @if($isTelnyx)
                    <div class="text-caption">A number you own in Telnyx and have assigned to the messaging profile.</div>
                @endif
                @error('sender_number')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>

            @foreach($fields as $key => $meta)
                <div class="mb-1">
                    <label class="form-label" for="{{ $key }}">
                        {{ $meta['label'] }}
                        @if($meta['required'])<span class="text-danger">*</span>@else<small class="text-muted">(optional)</small>@endif
                    </label>
                    <input type="{{ in_array($key, $secretFields, true) ? 'password' : 'text' }}" autocomplete="off" id="{{ $key }}" name="{{ $key }}"
                           class="form-control @error($key) is-invalid @enderror">
                    @if($isTelnyx && isset($help[$key]))
                        <div class="text-caption">{{ $help[$key] }}</div>
                    @endif
                    @error($key)
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            @endforeach

            <x-button type="submit" variant="primary">Connect</x-button>
        </form>
    </x-card>
@endsection

@if($isTelnyx)
    @section('page-script')
        <script>
            (function () {
                var button = document.getElementById('copy-webhook-url');
                var input = document.getElementById('webhook-url');
                var status = document.getElementById('copy-webhook-status');
                if (!button || !input) { return; }

                function done(ok) { status.textContent = ok ? 'Webhook URL copied.' : 'Press Ctrl/Cmd+C to copy the selected URL.'; }

                button.addEventListener('click', function () {
                    input.select();
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(input.value).then(function () { done(true); }, function () { done(false); });
                        return;
                    }
                    try { done(document.execCommand('copy')); } catch (e) { done(false); }
                });
            })();
        </script>
    @endsection
@endif
