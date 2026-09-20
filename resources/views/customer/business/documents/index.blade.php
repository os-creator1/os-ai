@extends('layouts/contentLayoutMaster')
@section('title', 'Documents')
@section('content')
<h4>Proposals and invoices</h4>
<x-flash-alert />
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="card p-2 mb-2">
    <h5>New draft</h5>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.store', [$workspaceUid, $businessUid]) }}">
        @csrf
        <label>Type <select name="kind" required><option value="proposal">Proposal</option><option value="invoice">Invoice</option></select></label>
        <label>Title <input name="title" required maxlength="200"></label>
        <label>Location <select name="location_uid" required>@foreach($locations as $location)<option value="{{ $location->uid }}">{{ $location->name }}</option>@endforeach</select></label>
        <label>Customer <select name="contact_uid" required>@foreach($contacts as $contact)<option value="{{ $contact->uid }}">{{ $contact->uid }}</option>@endforeach</select></label>
        <button class="btn btn-primary" type="submit">Create draft</button>
    </form>
</div>
<div class="card p-2">
    @forelse($documents as $document)
        <p><a href="{{ route('customer.workspaces.businesses.documents.show', [$workspaceUid, $businessUid, $document->uid]) }}">{{ $document->title }}</a> — {{ $document->kind->value }} — {{ $document->status->value }}</p>
    @empty<p>No documents yet.</p>@endforelse
    {{ $documents->links() }}
</div>
@endsection
