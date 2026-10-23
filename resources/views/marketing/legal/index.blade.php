@extends('marketing.layout')

@push('head')
<link rel="stylesheet" href="{{ $mg->asset('css/legal.css') }}">
@if ($drafts)<meta name="robots" content="noindex, nofollow">@endif
@endpush

@section('content')
@php
    $primary = array_filter($documents, fn ($d) => $d->primary);
    $addenda = array_filter($documents, fn ($d) => ! $d->primary);
@endphp
<section class="lg">
<header class="lg-head">
<div class="wrap">
<nav class="lg-crumbs" aria-label="Breadcrumb"><a href="{{ $mg->url('home') }}">Home</a><span aria-hidden="true">/</span><span aria-current="page">Legal Center</span></nav>
<span class="eyebrow">Legal</span>
<h1 class="lg-title">Legal Center</h1>
<p class="lg-lead">{{ config('legal.entity.description') }} These are the agreements and policies that govern its services.</p>
@if ($drafts)
<div class="lg-draft" role="status"><b>Draft — pending legal approval.</b> Documents marked “Draft” are not in force and must not be relied on or accepted as an agreement.</div>
@endif
</div>
</header>
<div class="wrap lg-index">
<h2 class="lg-index-h">Core documents</h2>
<div class="lg-cards">
@foreach ($primary as $d)
@include('marketing.legal._card', ['d' => $d])
@endforeach
</div>
@if ($addenda)
<h2 class="lg-index-h">Addenda and disclosures</h2>
<div class="lg-cards">
@foreach ($addenda as $d)
@include('marketing.legal._card', ['d' => $d])
@endforeach
</div>
@endif
</div>
</section>
@endsection

@section('footer')
@include('marketing.partials.footer-full')
@endsection
