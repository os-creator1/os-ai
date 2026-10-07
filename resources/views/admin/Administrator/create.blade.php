@extends('layouts/contentLayoutMaster')

@section('title', 'Invite administrator')

@section('content')
    <section id="admin-administrator-invite">
        @include('admin.partials.flash')

        <div class="row">
            <div class="col-md-7 col-12">
                <div class="card">
                    <div class="card-header"><h4 class="card-title">Invite administrator</h4></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.administrators.store') }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-6 mb-1">
                                    <label class="form-label" for="first_name">First name</label>
                                    <input class="form-control" id="first_name" name="first_name" value="{{ old('first_name') }}" required>
                                </div>
                                <div class="col-md-6 mb-1">
                                    <label class="form-label" for="last_name">Last name</label>
                                    <input class="form-control" id="last_name" name="last_name" value="{{ old('last_name') }}">
                                </div>
                                <div class="col-12 mb-1">
                                    <label class="form-label" for="email">Email</label>
                                    <input class="form-control" type="email" id="email" name="email" value="{{ old('email') }}" required>
                                </div>
                                <div class="col-12 mb-1">
                                    <span class="form-label d-block">Roles</span>
                                    @foreach ($roles as $role)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="roles[]" id="role-{{ $role->id }}" value="{{ $role->id }}" @checked(in_array($role->id, old('roles', [])))>
                                            <label class="form-check-label" for="role-{{ $role->id }}">{{ $role->name }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <p class="text-muted">They receive an email with a link to set their own password. You never see or choose it.</p>
                            <button class="btn btn-primary" type="submit">Send invitation</button>
                            <a class="btn btn-flat-secondary" href="{{ route('admin.administrators.index') }}">Cancel</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
