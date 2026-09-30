@extends('layouts/contentLayoutMaster')

@section('title', 'Website setup unavailable')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <x-empty-state icon="alert-triangle" title="Website setup isn't available yet" description="We don't have a guided setup questionnaire for this business type yet. Our team is notified and will let you know as soon as it's ready." />
        </div>
    </div>
@endsection
