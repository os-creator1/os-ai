@extends('marketing.layout')

@section('content')
@php
    $c = $mg->get('page_copy.customers');
    $t = $mg->testimonials();
    $byline = fn (array $s) => implode(' · ', array_filter([$s['businessName'], $s['category'], $s['location']]));
    $f = $t['featured'];
    $youtube = $mg->social()['YouTube'] ?? null;
@endphp
<section style="padding: 96px 0 56px">
<div class="wrap" style="display: flex; flex-direction: column; gap: 22px">
<span class="eyebrow">{{ $c['eyebrow'] }}</span>
<h1 style="margin: 0; font-size: clamp(44px, 6vw, 76px); line-height: 1; font-weight: 800; letter-spacing: -0.045em; max-width: 900px">{{ $c['title'] }}</h1>
<p class="lead" style="max-width: 640px">{{ $c['lead'] }}</p>
</div>
</section>

@if ($t['isEmpty'])
<section style="padding: 0 0 112px"><div class="wrap"><p class="lead">{{ $c['empty'] }}</p></div></section>
@else
<section aria-label="Featured story" style="padding: 0 0 80px">
<div class="wrap">
<article style="display: flex; flex-wrap: wrap; gap: 40px; align-items: center; padding: 28px; border-radius: 28px; background: #FFFFFF; border: 1px solid #E7E1DB">
<div style="flex: 1.4 1 520px">@include('marketing.partials.video', ['s' => $f, 'size' => 'lg'])</div>
<div style="flex: 1 1 340px; display: flex; flex-direction: column; gap: 16px">
@include('marketing.partials.testimonial-meta', ['s' => $f])
@if ($f['quote'])<p style="margin: 0; font-size: 26px; line-height: 1.35; font-weight: 600; letter-spacing: -0.015em">“{{ $f['quote'] }}”</p>@endif
<div style="display: flex; flex-direction: column; gap: 4px"><b style="font-size: 16px">{{ $f['customerName'] }}</b>@if ($byline($f))<span style="color: #5E5652">{{ $byline($f) }}</span>@endif</div>
</div>
</article>
</div>
</section>

<section aria-labelledby="all-h" style="padding: 0 0 112px">
<div class="wrap" style="display: flex; flex-direction: column; gap: 28px" data-filter-root>
<div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px">
<h2 id="all-h" class="h2" style="font-size: 30px">All stories</h2>
@if ($t['hasConfirmedContext'])
<div role="group" aria-label="Filter stories" style="display: flex; flex-wrap: wrap; gap: 8px">
@foreach ([['all', 'All'], ['motiongrove', 'MotionGrove customers'], ['jazmin_media', 'Jazmin Media clients']] as $i => [$key, $label])
<button type="button" class="pill" data-filter="{{ $key }}" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}" style="height: 42px; padding: 0 16px; font-size: 14.5px">{{ $label }}</button>
@endforeach
</div>
@endif
</div>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr)); gap: 28px">
@foreach ($t['all'] as $s)
<article data-context="{{ $s['context'] }}" style="display: flex; flex-direction: column; gap: 12px">
@include('marketing.partials.video', ['s' => $s, 'size' => 'md'])
@include('marketing.partials.testimonial-meta', ['s' => $s])
<b style="font-size: 17px">{{ $s['customerName'] }}</b>
@if ($byline($s))<span style="color: #5E5652; font-size: 14.5px">{{ $byline($s) }}</span>@endif
@if ($s['quote'])<p style="margin: 0; color: #4A4340; line-height: 1.55">“{{ $s['quote'] }}”</p>@endif
</article>
@endforeach
</div>
@if ($t['hasJazmin'])
<p style="margin: 0; font-size: 13.5px; color: #5E5652; line-height: 1.55; max-width: 820px; padding: 16px 18px; border-radius: 14px; background: #EFEBE7">{{ $mg->get('copy.jazmin_disclaimer_customers') }}</p>
@endif
@if ($t['hasUnspecified'])
<p style="margin: 0; font-size: 13.5px; color: #5E5652; line-height: 1.55; max-width: 820px; padding: 16px 18px; border-radius: 14px; background: #EFEBE7">{{ $mg->get('copy.unspecified_disclaimer') }}</p>
@endif
@if ($youtube)
<p style="margin: 0; font-size: 14px; color: #5E5652">More on our YouTube channel: <a href="{{ $youtube }}" rel="noopener" target="_blank" style="text-decoration: underline">{{ $youtube }}</a></p>
@endif
</div>
</section>
@endif

@include('marketing.partials.cta-panel', ['title' => $c['cta']['title'], 'lead' => $mg->hasBookDemo() ? $c['cta']['lead'] : null, 'buttons' => array_filter([
    $mg->hasBookDemo() ? ['label' => 'Book a demo', 'url' => $mg->bookDemoUrl(), 'style' => 'btn-p'] : ['label' => 'Get started', 'url' => $mg->getStartedUrl(), 'style' => 'btn-p'],
    ['label' => $c['cta']['secondary'], 'url' => $mg->url('pricing'), 'style' => 'ghost'],
])])
@endsection

@section('footer')
@include('marketing.partials.footer-compact')
@endsection
