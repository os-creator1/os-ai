@extends('layouts/contentLayoutMaster')

@section('title', 'New form')

@section('content')
    @php $scope = [$workspace->uid, $business->uid]; @endphp

    @include('customer.business.forms._messages')

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">New form</h4>
            <p class="card-text text-muted">It starts as a draft. Choose where to offer it, then activate it.</p>

            <form method="POST" action="{{ route('customer.workspaces.businesses.forms.store', $scope) }}" data-role="forms-create-form">
                @csrf

                @include('customer.business.forms._editor', ['form' => null, 'version' => null])

                <button type="submit" class="btn btn-primary">Create form</button>
                <a href="{{ route('customer.workspaces.businesses.forms.index', $scope) }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
