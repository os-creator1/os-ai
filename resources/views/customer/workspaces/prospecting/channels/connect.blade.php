@extends('layouts/contentLayoutMaster')

@section('title', 'Connect ' . $providerLabel)

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

    <x-card :padded="true">
        <form method="post" action="{{ route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, $provider]) }}">
            @csrf

            <div class="mb-1">
                <label class="form-label" for="sender_number">Sender number (include country code)</label>
                <input type="text" id="sender_number" name="sender_number"
                       class="form-control @error('sender_number') is-invalid @enderror"
                       placeholder="+1 555 123 4567" value="{{ old('sender_number') }}">
                @error('sender_number')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            @foreach($fields as $key => $meta)
                <div class="mb-1">
                    <label class="form-label" for="{{ $key }}">{{ $meta['label'] }}</label>
                    <input type="password" autocomplete="off" id="{{ $key }}" name="{{ $key }}"
                           class="form-control @error($key) is-invalid @enderror">
                    @error($key)
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            @endforeach

            <x-button type="submit" variant="primary">Connect</x-button>
        </form>
    </x-card>
@endsection
