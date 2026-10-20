@extends('marketing.layout')

{{-- Owner-only preview of the saved draft: the same field partial and card styles as /book-demo, inert. --}}
@push('head')
<meta name="robots" content="noindex">
<link rel="stylesheet" href="{{ $mg->asset('css/book-demo.css') }}">
@endpush

@section('content')
<section style="padding: 32px 0 48px">
<div class="wrap" style="max-width: 640px">
@if (count($formFields) === 0)
<div class="mgd-card" style="flex: none">
<h2 style="margin: 0 0 10px; font-size: 22px; font-weight: 700">Pick a time that suits you.</h2>
<p style="margin: 0; color: #4A4340; line-height: 1.6">This form has no questions, so visitors go straight to choosing a time. When they confirm, they are asked only for their first name and email.</p>
</div>
@else
<div class="mgd-card" style="flex: none">
<form onsubmit="return false" style="display: flex; flex-direction: column; gap: 18px" aria-label="Preview">
<h2 style="margin: 0; font-size: 22px; font-weight: 700">Tell us a little about you</h2>
@include('marketing.book-demo._fields', ['fields' => $formFields])
<button type="button" class="btn btn-p" style="min-height: 54px; font-size: 16px">Continue to choose a time</button>
</form>
</div>
@endif
</div>
</section>
@endsection
