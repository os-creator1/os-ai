@extends('layouts/contentLayoutMaster')

@section('title', 'New form')

@section('content')
    @php $scope = [$workspace->uid, $business->uid]; @endphp

    @include('customer.business.forms._messages')

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">New form</h4>
            <p class="card-text text-muted">Name it and pick a starting point. You'll design it visually next — it starts as a draft until you activate it.</p>

            <form method="POST" action="{{ route('customer.workspaces.businesses.forms.store', $scope) }}" data-role="forms-create-form">
                @csrf

                <div class="form-group">
                    <label for="name">Form name</label>
                    <input type="text" id="name" name="name" class="form-control" value="{{ old('name') }}" placeholder="e.g. Check availability" required autofocus>
                </div>

                <div class="form-group">
                    <label class="d-block">Starting point</label>
                    @foreach ($starters as $id => $label)
                        <div class="custom-control custom-radio mb-50">
                            <input type="radio" class="custom-control-input" id="starter-{{ $id }}" name="starter" value="{{ $id }}" @checked(old('starter', $loop->first ? $id : null) === $id)>
                            <label class="custom-control-label" for="starter-{{ $id }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>

                <button type="submit" class="btn btn-primary">Create and open builder</button>
                <a href="{{ route('customer.workspaces.businesses.forms.index', $scope) }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
