{{--
    One reusable template for all nine legal documents.
    Receives: $mg, $page, $meta, $doc (LegalDocuments::render()), $company (LegalCompany::current()), $related.
    $doc['html'] is Markdown output with raw HTML stripped and every company value escaped (LegalDocuments).
--}}
@extends('marketing.layout')

@php
    $d = $doc['meta'];
    $fmt = fn ($date) => \Illuminate\Support\Carbon::parse($date)->format('F j, Y');
@endphp

@section('content')
<article class="lg-page">
<div class="lg-hero">
<div class="wrap">
<nav class="lg-crumbs" aria-label="Breadcrumb"><a href="{{ $mg->url('home') }}">Home</a><span aria-hidden="true">/</span><a href="{{ url('legal') }}">Legal</a><span aria-hidden="true">/</span><span aria-current="page">{{ $d['nav'] }}</span></nav>
<p class="lg-cat">{{ $d['category'] }}</p>
<h1 class="lg-title">{{ $d['title'] }}</h1>
<p class="lg-intro">{{ $d['intro'] }}</p>
<div class="lg-meta-row">
<dl class="lg-meta">
<div><dt>Effective date</dt><dd>{{ ! empty($d['effective']) ? $fmt($d['effective']) : 'Set on publication' }}</dd></div>
<div><dt>Last updated</dt><dd><time datetime="{{ $d['updated'] }}">{{ $fmt($d['updated']) }}</time></dd></div>
<div><dt>Version</dt><dd>{{ $d['version'] }}</dd></div>
</dl>
<button type="button" class="btn btn-s lg-print" data-print>Print / Save as PDF</button>
</div>
</div>
</div>

<div class="wrap lg-layout">
<aside class="lg-side" aria-label="Document navigation">
<details class="lg-toc-m" open>
<summary>On this page</summary>
<nav aria-label="Contents (mobile)"><ol class="lg-toc-list">@foreach ($doc['toc'] as $s)<li><a href="#{{ $s['id'] }}">{{ $s['label'] }}</a></li>@endforeach</ol></nav>
</details>
<nav class="lg-toc" aria-label="Contents">
<p class="lg-side-h">On this page</p>
<ol class="lg-toc-list">@foreach ($doc['toc'] as $s)<li><a href="#{{ $s['id'] }}" data-toc>{{ $s['label'] }}</a></li>@endforeach</ol>
<div class="lg-q"><b>Questions?</b><a href="mailto:{{ $company['legal_email'] }}">{{ $company['legal_email'] }}</a></div>
</nav>
</aside>

<div class="lg-main">
<section class="lg-glance" aria-labelledby="lg-glance-h">
<h2 id="lg-glance-h">About this document</h2>
<ul>@foreach ($d['glance'] as $point)<li>{{ $point }}</li>@endforeach</ul>
<p class="lg-fine">A summary only. The full text below is the document.</p>
</section>

<div class="lg-body">{!! $doc['html'] !!}</div>

<section class="lg-company" aria-labelledby="lg-company-h">
<h2 id="lg-company-h" class="lg-h2">Company information</h2>
<dl>
<div><dt>Brand</dt><dd>{{ $company['brand_name'] }}</dd></div>
<div><dt>Legal entity</dt><dd>{{ $company['legal_entity'] }}</dd></div>
<div><dt>Company number</dt><dd>{{ $company['company_number'] }}</dd></div>
<div><dt>Registered office</dt><dd>{{ $company['registered_address'] }}</dd></div>
<div><dt>Country</dt><dd>{{ $company['country'] }}</dd></div>
<div><dt>Legal contact</dt><dd><a href="mailto:{{ $company['legal_email'] }}">{{ $company['legal_email'] }}</a></dd></div>
</dl>
</section>

<nav class="lg-more" aria-label="Other legal documents">
<b>Other legal documents</b>
<ul>@foreach ($related as $slug => $r)@if ($slug !== $d['slug'])<li><a href="{{ $r['url'] }}">{{ $r['nav'] }}</a></li>@endif @endforeach</ul>
<a class="lg-top" href="#main">Back to top &uarr;</a>
</nav>
</div>
</div>
</article>
@endsection

@section('footer')
@include('marketing.partials.footer-full')
@endsection
