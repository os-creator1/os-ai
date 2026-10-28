@extends('layouts/authCover')

@section('title', 'Accept invitation')

@section('auth-form')
    <h1 class="card-title fw-bold mb-1 h2">Join {{ $account }}</h1>
    <p class="card-text mb-2">You've been invited to join {{ $account }} on MotionGrove as {{ $role }}.</p>

    <form method="POST" action="{{ route('workspace-invitations.accept', ['uid' => $uid, 'token' => $token]) }}">
        @csrf
        <button type="submit" class="btn btn-primary w-100">Accept invitation</button>
    </form>
@endsection
