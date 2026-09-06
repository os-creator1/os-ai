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
                            <x-badge variant="neutral">Disabled</x-badge>
                        @endif
                    </dd>

                    <dt class="col-sm-4">Webhook URL</dt>
                    <dd class="col-sm-8"><code>{{ $webhookUrl }}</code></dd>
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
