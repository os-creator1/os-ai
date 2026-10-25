@extends('layouts/authCover')

@section('title', 'Invitation not available')

@section('auth-form')
    <h1 class="card-title fw-bold mb-1 h2">Invitation not available</h1>
    <p class="card-text mb-2">This MotionGrove invitation is no longer valid. It may have expired, been withdrawn or already been used. Ask the person who invited you to send a new one.</p>
    <p><a href="{{ route('login') }}">Go to sign in</a></p>
@endsection
