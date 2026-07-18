@extends('layouts/fullLayoutMaster')

@section('title', __('locale.labels.privacy_policy'))

@section('content')
    <div class="row">
        <div class="col-12 p-4">
            {!! $privacyPolicyData !!}

        </div>
    </div>
@endsection
