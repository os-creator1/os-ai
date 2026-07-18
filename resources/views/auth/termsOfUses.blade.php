@extends('layouts/fullLayoutMaster')

@section('title', __('locale.labels.terms_of_use'))

@section('content')
    <div class="row">
        <div class="col-12 p-4">
            {!! $termsOfUseData !!}

        </div>
    </div>
@endsection
