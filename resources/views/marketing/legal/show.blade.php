@extends('marketing.layout')

@push('head')
<link rel="stylesheet" href="{{ $mg->asset('css/legal.css') }}">
@if ($draft)<meta name="robots" content="noindex, nofollow">@endif
@endpush

@section('content')
@php
    $others = array_filter($documents, fn ($d) => $d->slug !== $doc->slug);
@endphp
<article class="lg" aria-labelledby="lg-title">
<header class="lg-head">
<div class="wrap">
<nav class="lg-crumbs" aria-label="Breadcrumb"><a href="{{ $mg->url('home') }}">Home</a><span aria-hidden="true">/</span><a href="{{ $registry->indexUrl() }}">Legal Center</a><span aria-hidden="true">/</span><span aria-current="page">{{ $doc->title }}</span></nav>
<span class="eyebrow">{{ $doc->category }}</span>
<h1 id="lg-title" class="lg-title">{{ $doc->title }}</h1>
<p class="lg-lead">{{ $doc->description }}</p>
<dl class="lg-meta">
<div><dt>Effective date</dt><dd>{{ $doc->effectiveLabel() }}</dd></div>
<div><dt>Last updated</dt><dd>{{ $doc->updatedLabel() }}</dd></div>
<div><dt>Version</dt><dd>{{ $doc->version }}</dd></div>
</dl>
<div class="lg-actions">
<button type="button" class="btn btn-s" data-lg-print>Print / Save as PDF</button>
<a class="btn btn-s" href="{{ $registry->indexUrl() }}">All legal documents</a>
</div>
@if ($draft)
<div class="lg-draft" role="status"><b>Draft — pending legal approval.</b> This text has not been approved for release, is not in force and must not be relied on or accepted as an agreement.@if ($doc->releaseNote) {{ $doc->releaseNote }}@endif</div>
@endif
</div>
</header>

<div class="wrap lg-grid">
<aside class="lg-toc" aria-label="Contents">
<details open data-lg-toc>
<summary>Contents</summary>
<ol>
@foreach ($sections as $s)
<li><a href="#{{ $s['id'] }}">@if ($s['number'] !== null)<span>{{ $s['number'] }}</span>@endif{{ $s['title'] }}</a></li>
@endforeach
</ol>
</details>
</aside>

<div class="lg-doc">
<p class="lg-print-only"><b>{{ $doc->title }}</b> · Version {{ $doc->version }} · Effective {{ $doc->effectiveLabel() }} · {{ $registry->url($doc->slug) }}</p>
<div class="lg-body">{!! $html !!}</div>
<p class="lg-end"><a href="#lg-title" data-lg-top>Back to top ↑</a></p>
</div>

<aside class="lg-side" aria-label="Document summary">
<div class="lg-card">
<h2>Document summary</h2>
<ul>
@foreach ($doc->summary as $point)
<li>{{ $point }}</li>
@endforeach
</ul>
<hr>
<dl>
<div><dt>Provider</dt><dd>{{ config('legal.entity.name') }}, trading as {{ config('legal.entity.brand') }}</dd></div>
<div><dt>Version</dt><dd>{{ $doc->version }}</dd></div>
<div><dt>Fingerprint</dt><dd><code title="sha256 of the exact text of this version">{{ substr($doc->hash, 0, 12) }}</code></dd></div>
</dl>
<p class="lg-fine">{{ config('legal.entity.description') }}</p>
</div>
@if ($others)
<div class="lg-card">
<h2>Related documents</h2>
<ul class="lg-links">
@foreach ($others as $other)
<li><a href="{{ $registry->url($other->slug) }}">{{ $other->short }}</a></li>
@endforeach
</ul>
</div>
@endif
</aside>
</div>
</article>
<script src="{{ $mg->asset('js/legal.js') }}" defer></script>
@endsection

@section('footer')
@include('marketing.partials.footer-full')
@endsection
