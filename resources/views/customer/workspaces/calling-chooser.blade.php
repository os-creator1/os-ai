@extends('layouts/contentLayoutMaster')

@section('title', 'Calling')

@section('content')
    <div style="max-width:40rem" data-role="calling-chooser">
        <h1 style="font-size:1.4rem;margin:0 0 .25rem">Calling</h1>
        <p style="color:var(--color-text-muted,#6f6d67);margin:0 0 1.25rem">Choose the business whose calls you want to manage. Calls and forwarding are set up separately for each business.</p>
        <ul style="list-style:none;margin:0;padding:0;display:grid;gap:.6rem">
            @foreach ($businesses as $business)
                <li><a href="{{ route('customer.workspaces.businesses.calling.show', [$workspaceUid, $business->uid]) }}" data-role="calling-business" style="display:block;padding:.85rem 1rem;border:1px solid var(--color-border-neutral,#e5e1da);border-radius:.7rem;text-decoration:none;font-weight:600">{{ $business->name }}</a></li>
            @endforeach
        </ul>
    </div>
@endsection
