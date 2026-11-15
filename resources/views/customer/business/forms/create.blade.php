@extends('layouts/contentLayoutMaster')

@section('title', 'New form')

@section('content')
    {{-- No-JavaScript / direct-link fallback for the New form dialog: the same card, as a page. --}}
    @include('customer.business.forms._assets')
    @include('customer.business.forms._messages')

    <div class="mgf" data-role="forms-new-page">
        <div class="mgf-pagehead">
            <div>
                <h1 class="sr-only">New form</h1>
                <a class="mgf-back" href="{{ route('customer.workspaces.businesses.forms.index', [$workspace->uid, $business->uid]) }}">@include('customer.business.forms._icon', ['n' => 'back', 'style' => 'width:14px;height:14px']) Forms</a>
            </div>
        </div>
        <div style="max-width:620px" data-role="forms-new-card-wrap">
            @include('customer.business.forms._new_form', ['dialog' => false])
        </div>
    </div>
@endsection

@section('page-script')
    <script src="{{ asset('js/forms/new-form-dialog.js') }}?v={{ filemtime(public_path('js/forms/new-form-dialog.js')) }}"></script>
@endsection
