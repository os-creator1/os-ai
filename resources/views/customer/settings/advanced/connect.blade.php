@extends('layouts/contentLayoutMaster')

@section('title', 'Connect ' . $providerLabel)

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-0">Connect {{ $providerLabel }}</h4>
                <p class="text-caption mb-0">Advanced, provider-specific setup — most of the time you won't need to come back here after connecting.</p>
            </div>
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

            @if($mmsSupported)
                <div class="form-check mb-1">
                    <input type="checkbox" class="form-check-input" id="enable_mms" name="enable_mms" value="1" checked>
                    <label class="form-check-label" for="enable_mms">Enable MMS (send pictures and other media, not just text)</label>
                </div>

                <div id="mms-fields" @if(empty($mmsFields)) hidden @endif>
                    @foreach($mmsFields as $key => $meta)
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
                </div>
            @endif

            <x-button type="submit" variant="primary">Connect</x-button>
        </form>
    </x-card>
@endsection

@if($mmsSupported && ! empty($mmsFields))
@section('page-script')
    <script>
        $(document).ready(function () {
            $('#enable_mms').on('change', function () {
                $('#mms-fields').prop('hidden', ! this.checked);
            });
        });
    </script>
@endsection
@endif
