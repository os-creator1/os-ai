@extends('marketing.layout')

{{-- Owner-only preview of the saved draft: the same field partial and card styles as /book-demo, inert. --}}
@push('head')
<meta name="robots" content="noindex">
<link rel="stylesheet" href="{{ $mg->asset('css/book-demo.css') }}">
@endpush

@section('content')
<section style="padding: 32px 0 48px">
<div class="wrap" style="max-width: 640px">
<div class="mgd-card" style="flex: none">
<form onsubmit="return false" style="display: flex; flex-direction: column; gap: 18px" aria-label="Preview">
<h2 style="margin: 0; font-size: 22px; font-weight: 700">Tell us a little about you</h2>
@include('marketing.book-demo._fields', ['fields' => $formFields])
<button type="button" class="btn btn-p" style="min-height: 54px; font-size: 16px">Continue to choose a time</button>
</form>
</div>
</div>
</section>
@endsection
