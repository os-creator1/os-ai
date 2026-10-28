@extends('layouts/authCover')

@section('title', 'Join ' . $account)

@section('auth-form')
    <h1 class="card-title fw-bold mb-1 h2">Join {{ $account }}</h1>
    <p class="card-text mb-2">You've been invited to join {{ $account }} on MotionGrove as {{ $role }}. Choose a password to create your sign-in.</p>

    <form method="POST" action="{{ route('workspace-invitations.register', ['uid' => $uid, 'token' => $token]) }}" novalidate>
        @csrf

        <div class="mb-1">
            <label class="form-label" for="invite-email">Email</label>
            <input id="invite-email" type="email" class="form-control" value="{{ $email }}" readonly>
        </div>

        <div class="mb-1">
            <label class="form-label" for="first_name">First name</label>
            <input id="first_name" type="text" name="first_name" value="{{ old('first_name') }}" class="form-control @error('first_name') is-invalid @enderror" required autocomplete="given-name">
            @error('first_name') <div class="text-danger small mt-50">{{ $message }}</div> @enderror
        </div>

        <div class="mb-1">
            <label class="form-label" for="last_name">Last name</label>
            <input id="last_name" type="text" name="last_name" value="{{ old('last_name') }}" class="form-control @error('last_name') is-invalid @enderror" autocomplete="family-name">
        </div>

        <div class="mb-1">
            <label class="form-label" for="password">Password</label>
            <input id="password" type="password" name="password" class="form-control @error('password') is-invalid @enderror" required minlength="8" autocomplete="new-password">
            @error('password') <div class="text-danger small mt-50">{{ $message }}</div> @enderror
        </div>

        <div class="mb-2">
            <label class="form-label" for="password-confirm">Confirm password</label>
            <input id="password-confirm" type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary w-100">Create my sign-in and join</button>
    </form>

    <p class="text-center mt-2 mb-0">Already have a MotionGrove account? <a href="{{ route('login') }}">Sign in to accept</a></p>
@endsection
