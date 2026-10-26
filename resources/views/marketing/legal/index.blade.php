{{-- The Legal Center hub: every legal document, primary first. Receives $mg, $page, $meta, $primary, $supporting, $company. --}}
@extends('marketing.layout')

@section('content')
<div class="lg-page">
<div class="lg-hero">
<div class="wrap">
<nav class="lg-crumbs" aria-label="Breadcrumb"><a href="{{ $mg->url('home') }}">Home</a><span aria-hidden="true">/</span><span aria-current="page">Legal</span></nav>
<p class="lg-cat">Legal</p>
<h1 class="lg-title">Legal Center</h1>
<p class="lg-intro">The terms, policies and addenda that govern the use of {{ $company['brand_name'] }}, operated by {{ $company['legal_entity'] }}.</p>
</div>
</div>
<div class="wrap lg-hub">
<h2 class="lg-hub-h">Core documents</h2>
<ul class="lg-cards">@foreach ($primary as $d)<li><a href="{{ $d['url'] }}"><b>{{ $d['title'] }}</b><span>{{ $d['intro'] }}</span></a></li>@endforeach</ul>
<h2 class="lg-hub-h">Supporting documents</h2>
<ul class="lg-cards">@foreach ($supporting as $d)<li><a href="{{ $d['url'] }}"><b>{{ $d['title'] }}</b><span>{{ $d['intro'] }}</span></a></li>@endforeach</ul>
<p class="lg-fine">{{ $company['legal_entity'] }} (company number {{ $company['company_number'] }}), {{ $company['registered_address'] }}. Legal contact: <a href="mailto:{{ $company['legal_email'] }}">{{ $company['legal_email'] }}</a></p>
</div>
</div>
@endsection

@section('footer')
@include('marketing.partials.footer-full')
@endsection
