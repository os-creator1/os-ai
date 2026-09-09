@extends('layouts/contentLayoutMaster')

@section('title', 'Connect ' . $providerLabel)

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Connect {{ $providerLabel }}</h4>
            <x-button variant="outline" size="sm" icon="arrow-left"
                      :href="route('customer.workspaces.businesses.channels.index', [$workspaceUid, $businessUid])">
                Back
            </x-button>
        </div>
    </div>

    <x-card :padded="true">
        <form method="post" action="{{ route('customer.workspaces.businesses.channels.connect', [$workspaceUid, $businessUid, $provider]) }}">
            @csrf

            @foreach($fields as $key => $meta)
                <div class="mb-1">
                    <label class="form-label {{ $meta['required'] ? 'required' : '' }}" for="{{ $key }}">{{ $meta['label'] }}</label>
                    <input type="password" autocomplete="off" id="{{ $key }}" name="{{ $key }}"
                           class="form-control @error($key) is-invalid @enderror"
                           @if($meta['required']) required @endif>
                    @error($key)
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            @endforeach

            <x-button type="submit" variant="primary">Connect</x-button>
        </form>
    </x-card>
@endsection
