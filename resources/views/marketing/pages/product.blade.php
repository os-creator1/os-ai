@extends('marketing.layout')

@section('content')
@php($c = $mg->get('page_copy.product'))
<section style="padding: 96px 0 56px">
<div class="wrap" style="display: flex; flex-direction: column; gap: 22px; align-items: flex-start">
<span class="eyebrow">{{ $c['eyebrow'] }}</span>
<h1 style="margin: 0; font-size: clamp(44px, 6vw, 76px); line-height: 1; font-weight: 800; letter-spacing: -0.045em; max-width: 960px">{{ $c['title'] }} <span style="color: #B4514A">{{ $c['title_accent'] }}</span></h1>
<p class="lead" style="max-width: 680px">{{ $c['lead'] }}</p>
<nav aria-label="Jump to a feature" style="display: flex; flex-wrap: wrap; gap: 8px; padding-top: 10px">
@foreach ($mg->productJump() as [$anchor, $label])
<a href="#{{ $anchor }}" class="chip" style="height: 40px; padding: 0 16px; font-size: 14.5px; background: #FFFFFF; border: 1px solid #E7E1DB">{{ $label }}</a>
@endforeach
</nav>
</div>
</section>

<div class="wrap">
@foreach ($mg->productFeatureOrder() as $featureKey)
@include('marketing.product._'.$featureKey)
@endforeach
@if ($mg->productMore() !== [])
<section id="more" style="padding: 72px 0 112px; border-top: 1px solid #E7E1DB; display: flex; flex-direction: column; gap: 32px" aria-labelledby="m7">
<h2 id="m7" class="h2">{{ $c['more_title'] ?? 'Also included' }}</h2>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px">
@foreach ($mg->productMore() as [$cardTitle, $cardText, $cardDark])
<div class="card" style="padding: 24px; display: flex; flex-direction: column; gap: 8px{{ $cardDark ? '; background: #181413; color: #FFFFFF; border-color: #181413' : '' }}"><b style="font-size: 17px">{{ $cardTitle }}</b><span style="color: {{ $cardDark ? '#C9C0BA' : '#5E5652' }}; line-height: 1.55">{{ $cardText }}</span></div>
@endforeach
</div>
<p style="margin: 0; font-size: 13px; color: #6B625E">Feature availability depends on your plan. <a href="{{ $mg->url('pricing') }}" style="text-decoration: underline">Compare plans</a>.</p>
</section>
@endif
</div>

@include('marketing.partials.cta-panel', ['title' => $c['cta']['title'], 'lead' => $c['cta']['lead'], 'buttons' => array_filter([
    ['label' => 'Get started', 'url' => $mg->getStartedUrl(), 'style' => 'btn-p'],
    $mg->hasBookDemo() ? ['label' => $c['cta']['secondary'], 'url' => $mg->bookDemoUrl(), 'style' => 'ghost'] : null,
])])
@endsection

@section('footer')
@include('marketing.partials.footer-compact')
@endsection
