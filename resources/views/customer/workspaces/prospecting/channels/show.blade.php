@extends('layouts/contentLayoutMaster')

@section('title', $providerLabel . ' channel')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ $providerLabel }}</h4>
            <x-button variant="outline" size="sm" icon="arrow-left"
                      :href="route('customer.workspaces.prospecting.channels.index', $workspaceUid)">
                Back
            </x-button>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-2">
            <x-card title="Channel" :padded="true">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Provider</dt>
                    <dd class="col-sm-8">{{ $providerLabel }}</dd>

                    <dt class="col-sm-4">Sender number</dt>
                    <dd class="col-sm-8">{{ $channel->sender_number }}</dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        @if($channel->status === 'active')
                            <x-badge variant="success">Active</x-badge>
                        @else
                            @if($channel->status === 'disconnected')
                                <x-badge variant="neutral">Disconnected</x-badge>
                            @else
                                <x-badge variant="neutral">Disabled</x-badge>
                            @endif
                        @endif
                    </dd>

                    <dt class="col-sm-4">Webhook URL</dt>
                    <dd class="col-sm-8"><code>{{ $webhookUrl }}</code>
                        @if($channel->provider === \App\Models\SendingServer::TYPE_TELNYX)
                            <div class="text-caption">Set this as the webhook URL of your Telnyx messaging profile. Events are accepted only if they are signed with your webhook public key.</div>
                            @if(! empty($legacyWebhookUrl))
                                <div class="text-caption mt-50">Only if this number also runs the older AI Prospecting agent, use <code>{{ $legacyWebhookUrl }}</code> instead.</div>
                            @endif
                        @endif
                    </dd>

                    @if($channel->provider === \App\Models\SendingServer::TYPE_TELNYX)
                        <dt class="col-sm-4">Webhook key</dt>
                        <dd class="col-sm-8">{{ trim((string) $channel->webhook_public_key) !== '' ? 'Saved' : 'Not saved yet' }}</dd>
                    @endif
                </dl>

                <div class="d-flex gap-2 mt-3">
                    @if($channel->status === 'active')
                        <form method="post" action="{{ route('customer.workspaces.prospecting.channels.disable', [$workspaceUid, $channel->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="outline" size="sm">Disable</x-button>
                        </form>
                    @else
                        <form method="post" action="{{ route('customer.workspaces.prospecting.channels.enable', [$workspaceUid, $channel->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="primary" size="sm">Enable</x-button>
                        </form>
                    @endif
                </div>
            </x-card>
        </div>

        @if($channel->provider === \App\Models\SendingServer::TYPE_TELNYX && $channel->status !== 'disconnected')
        <div class="col-12 mb-2">
            <x-card title="Voice calling (your own Telnyx account)" :padded="true">
                <p class="text-caption">Calls placed from your CRM with the Call button use your Telnyx account and number, and Telnyx bills the usage to you. MotionGrove does not change anything in your Telnyx account. This covers outbound browser calls only; incoming calls are not forwarded from your own number yet.</p>

                <dl class="row mb-1">
                    @foreach(\App\Library\Voice\Byo\AgencyByoVoice::STEPS as $stepKey => $stepLabel)
                        <dt class="col-sm-4">{{ $stepLabel }}</dt>
                        <dd class="col-sm-8">{!! in_array($stepKey, $voiceMissing, true) ? '<span class="text-danger">Missing or not verified</span>' : 'Verified' !!}</dd>
                    @endforeach
                    <dt class="col-sm-4">Signed voice webhook</dt>
                    <dd class="col-sm-8">{{ $channel->voice_webhook_verified_at ? 'Received a correctly signed event' : 'Waiting for the first signed event (placed with your first call)' }}</dd>
                    <dt class="col-sm-4">Voice status</dt>
                    <dd class="col-sm-8">{{ $channel->voice_enabled ? 'On' : 'Off' }}</dd>
                </dl>

                <form method="post" action="{{ route('customer.workspaces.prospecting.channels.voice.save', [$workspaceUid, $channel->uid]) }}" class="mb-1">
                    @csrf
                    @method('PUT')
                    <div class="row">
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="voice_connection_id">Voice (Call Control) connection ID</label>
                            <input type="text" autocomplete="off" id="voice_connection_id" name="voice_connection_id" value="{{ old('voice_connection_id', $channel->voice_connection_id) }}" class="form-control @error('voice_connection_id') is-invalid @enderror">
                            @error('voice_connection_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="voice_webrtc_connection_id">WebRTC credential connection ID</label>
                            <input type="text" autocomplete="off" id="voice_webrtc_connection_id" name="voice_webrtc_connection_id" value="{{ old('voice_webrtc_connection_id', $channel->voice_webrtc_connection_id) }}" class="form-control @error('voice_webrtc_connection_id') is-invalid @enderror">
                            @error('voice_webrtc_connection_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="voice_number">Voice number</label>
                            <input type="text" autocomplete="off" id="voice_number" name="voice_number" placeholder="+14155552671" value="{{ old('voice_number', $channel->voice_number) }}" class="form-control @error('voice_number') is-invalid @enderror">
                            @error('voice_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <x-button type="submit" variant="outline" size="sm">Save voice details</x-button>
                </form>

                <div class="text-caption mb-1">Set this as the webhook URL of your Telnyx Call Control application (signed with the webhook public key you saved above): <code>{{ $voiceWebhookUrl }}</code></div>

                <div class="d-flex gap-2 mt-2">
                    <form method="post" action="{{ route('customer.workspaces.prospecting.channels.voice.verify', [$workspaceUid, $channel->uid]) }}">
                        @csrf
                        <x-button type="submit" variant="outline" size="sm">Verify voice setup with Telnyx</x-button>
                    </form>
                    @if($channel->voice_enabled)
                        <form method="post" action="{{ route('customer.workspaces.prospecting.channels.voice.disable', [$workspaceUid, $channel->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="outline" size="sm">Turn voice off</x-button>
                        </form>
                    @else
                        <form method="post" action="{{ route('customer.workspaces.prospecting.channels.voice.enable', [$workspaceUid, $channel->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="primary" size="sm">Turn voice on</x-button>
                        </form>
                    @endif
                </div>
            </x-card>
        </div>
        @endif

        <div class="col-md-6 mb-2">
            <x-card title="Credentials" :padded="true">
                <form method="post" action="{{ route('customer.workspaces.prospecting.channels.update', [$workspaceUid, $channel->uid]) }}">
                    @csrf
                    @method('PUT')

                    @foreach($fields as $key => $meta)
                        <div class="mb-1">
                            <label class="form-label" for="{{ $key }}">{{ $meta['label'] }}</label>
                            <input type="password" autocomplete="off" id="{{ $key }}" name="{{ $key }}"
                                   class="form-control @error($key) is-invalid @enderror"
                                   placeholder="Leave blank to keep current value">
                            @error($key)
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    @endforeach

                    <x-button type="submit" variant="primary">Save</x-button>
                </form>
            </x-card>
        </div>
    </div>
@endsection
