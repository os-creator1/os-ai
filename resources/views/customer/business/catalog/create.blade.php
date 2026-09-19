@extends('layouts/contentLayoutMaster')

@section('title', 'Add package or product')

@section('content')
    @php $scope = [$workspace->uid, $business->uid]; @endphp

    @include('customer.business.catalog._messages')

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Add a package or product</h4>
            <p class="card-text text-muted">It is added to <strong>{{ $business->name }}</strong>'s one catalog and offered at every location until you say otherwise.</p>

            <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.store', $scope) }}" data-role="catalog-create-form">
                @csrf

                @include('customer.business.catalog._form', ['item' => null])

                <button type="submit" class="btn btn-primary">Add to catalog</button>
                <a href="{{ route('customer.workspaces.businesses.catalog.index', $scope) }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
