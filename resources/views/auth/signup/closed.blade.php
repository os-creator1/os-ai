@extends('layouts/authCover')

@section('title', __('Create your account'))

@section('auth-form')
    {{--
        Signup is unavailable (closed by the owner, or nothing is sellable).
        The customer still sees the full Business OS auth shell — never a bare
        page — with one obvious way forward.
    --}}
    <h1 class="card-title fw-bold mb-1 h2">{{ __('Create your account') }}</h1>
    <p class="card-text mb-2" data-role="signup-closed">{{ __('New registrations are temporarily unavailable.') }}</p>
    <a class="btn btn-primary w-100" href="{{ route('login') }}">{{ __('Sign in') }}</a>
@endsection
