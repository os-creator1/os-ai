@extends('layouts/contentLayoutMaster')

@section('title', 'Edit administrator')

@section('content')
    <section id="admin-administrator-edit">
        @include('admin.partials.flash')

        @php($current = array_map('intval', array_filter(explode(',', (string) $get_roles))))

        <div class="row">
            <div class="col-lg-7 col-12">
                <div class="card mb-2">
                    <div class="card-header"><h4 class="card-title">{{ $administrator->email }}</h4></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.administrators.update', $administrator->uid) }}">
                            @csrf
                            @method('PUT')
                            <div class="row">
                                <div class="col-md-6 mb-1">
                                    <label class="form-label" for="first_name">First name</label>
                                    <input class="form-control" id="first_name" name="first_name" value="{{ old('first_name', $administrator->first_name) }}" required>
                                </div>
                                <div class="col-md-6 mb-1">
                                    <label class="form-label" for="last_name">Last name</label>
                                    <input class="form-control" id="last_name" name="last_name" value="{{ old('last_name', $administrator->last_name) }}">
                                </div>
                                <div class="col-12 mb-1">
                                    <span class="form-label d-block">Roles</span>
                                    @foreach ($roles as $role)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="roles[]" id="role-{{ $role->id }}" value="{{ $role->id }}" @checked(in_array($role->id, old('roles', $current)))>
                                            <label class="form-check-label" for="role-{{ $role->id }}">{{ $role->name }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <button class="btn btn-primary" type="submit">Save changes</button>
                            <a class="btn btn-flat-secondary" href="{{ route('admin.administrators.index') }}">Back</a>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 col-12">
                <div class="card mb-2" id="administrator-account-actions">
                    <div class="card-header"><h4 class="card-title">Account</h4></div>
                    <div class="card-body">
                        <p>Status: @if ($administrator->status)<span class="badge badge-light-success">Active</span>@else<span class="badge badge-light-secondary">Inactive</span>@endif</p>
                        <form class="mb-2" method="POST" action="{{ route('admin.administrators.resend-invitation', $administrator->uid) }}">@csrf
                            <button class="btn btn-outline-primary" type="submit">Resend invitation / password link</button></form>
                        <form method="POST" action="{{ route('admin.administrators.status', $administrator->uid) }}">
                            @csrf
                            <input type="hidden" name="active" value="{{ $administrator->status ? 0 : 1 }}">
                            <input class="form-control mb-1" name="reason" placeholder="Reason (audited)" required minlength="3" maxlength="500" aria-label="Reason">
                            <button class="btn {{ $administrator->status ? 'btn-danger' : 'btn-success' }}" type="submit">{{ $administrator->status ? 'Deactivate' : 'Activate' }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
