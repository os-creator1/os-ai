@extends('layouts/contentLayoutMaster')
@section('title', 'Document draft')
@section('content')
<a href="{{ route('customer.workspaces.businesses.documents.index', [$workspaceUid, $businessUid]) }}">Documents</a>
<h4>{{ $document->title }} <small>{{ $document->status->value }}</small></h4>
<x-flash-alert />
@if(isset($errors) && $errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
@if($version)
@php($base = [$workspaceUid, $businessUid, $document->uid])
<div class="card p-2 mb-2">
    <h5>Draft details</h5>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.update', $base) }}">
        @csrf @method('PATCH')
        <label>Title <input name="title" value="{{ $document->title }}" required maxlength="200"></label>
        <label>Body and terms <textarea name="content[body]">{{ $version->content['body'] ?? '' }}</textarea></label>
        <button class="btn btn-primary" type="submit">Save</button>
    </form>
</div>
<div class="card p-2 mb-2">
    <h5>Lines</h5>
    @php($orderedLines = $version->lineItems->sortBy('position')->values())
    @foreach($orderedLines as $index => $line)
        <div>{{ $line->name }} — {{ $line->quantity }} × {{ $line->unit_price_minor }} = {{ $line->line_total_minor }} {{ $line->currency_code }}
            <form method="post" action="{{ route('customer.workspaces.businesses.documents.lines.destroy', [...$base, $line->uid]) }}">@csrf @method('DELETE')<button type="submit">Remove</button></form>
            @if($index > 0)
            <form method="post" action="{{ route('customer.workspaces.businesses.documents.lines.order', $base) }}">
                @csrf @method('PUT')
                @foreach($orderedLines as $orderIndex => $orderedLine)
                    <input type="hidden" name="line_uids[]" value="{{ $orderedLines[$orderIndex === $index - 1 ? $index : ($orderIndex === $index ? $index - 1 : $orderIndex)]->uid }}">
                @endforeach
                <button type="submit">Move up</button>
            </form>
            @endif
        </div>
    @endforeach
    <strong>Total: {{ $version->total_minor }} {{ $version->currency_code }} (minor units)</strong>
    <h6>Add catalog package</h6>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.catalog-lines.store', $base) }}">
        @csrf
        <select name="catalog_item_uid" required>@foreach($catalogItems as $item)<option value="{{ $item->uid }}">{{ $item->name }}</option>@endforeach</select>
        <label>Quantity <input type="number" name="quantity" min="1" value="1" required></label>
        <label>Explicit price (quote-only, minor units) <input type="number" name="explicit_price_minor" min="0"></label>
        <button type="submit">Add package</button>
    </form>
    <h6>Add custom line</h6>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.custom-lines.store', $base) }}">
        @csrf
        <label>Name <input name="name" maxlength="200" required></label>
        <label>Quantity <input type="number" name="quantity" min="1" value="1" required></label>
        <label>Unit price (minor units) <input type="number" name="unit_price_minor" min="0" required></label>
        <button type="submit">Add custom line</button>
    </form>
</div>
<div class="card p-2 mb-2">
    <h5>Payment terms</h5>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.schedule.update', $base) }}">
        @csrf @method('PUT')
        <input type="hidden" name="terms[0][kind]" value="full">
        <input type="hidden" name="terms[0][currency_code]" value="{{ $document->currency_code }}">
        <label>Full amount (minor units) <input type="number" name="terms[0][amount_minor]" min="0" value="{{ $version->total_minor }}" required></label>
        <button type="submit">Set full payment</button>
    </form>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.schedule.update', $base) }}">
        @csrf @method('PUT')
        <input type="hidden" name="terms[0][kind]" value="deposit"><input type="hidden" name="terms[0][currency_code]" value="{{ $document->currency_code }}">
        <input type="hidden" name="terms[1][kind]" value="balance"><input type="hidden" name="terms[1][currency_code]" value="{{ $document->currency_code }}">
        <label>Deposit <input type="number" name="terms[0][amount_minor]" min="0" required></label>
        <label>Balance <input type="number" name="terms[1][amount_minor]" min="0" required></label>
        <button type="submit">Set deposit and balance</button>
    </form>
    @foreach($version->paymentScheduleItems as $term)<p>{{ $term->kind->value }}: {{ $term->amount_minor }} {{ $term->currency_code }}</p>@endforeach
</div>
@endif
@if(in_array($document->status->value, ['draft', 'sent', 'signed']))
<form method="post" action="{{ route('customer.workspaces.businesses.documents.void', [$workspaceUid, $businessUid, $document->uid]) }}">
    @csrf <label>Void reason <input name="reason" required maxlength="255"></label><button type="submit">Void document</button>
</form>
@endif
@endsection
