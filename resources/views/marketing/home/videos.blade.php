@php
    $c = $mg->get('copy.home.videos');
    $t = $mg->testimonials();
    $byline = fn (array $s) => implode(' · ', array_filter([$s['businessName'], $s['category'], $s['location']]));
@endphp
@if ($mg->section('home.testimonials') && ! $t['isEmpty'])
<section class="sec" aria-labelledby="vid-h" style="background: #FFFFFF; border-top: 1px solid #E7E1DB; border-bottom: 1px solid #E7E1DB">
<div class="wrap vids-wrap" style="display: flex; flex-direction: column; gap: 48px">
<div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 24px">
<div style="display: flex; flex-direction: column; gap: 18px; max-width: 700px"><span class="eyebrow">{{ $c['eyebrow'] }}</span><h2 id="vid-h" class="h2"><span class="hide-m">{{ $c['title'] }}</span><span class="show-m">{{ $c['title_mobile'] }}</span></h2></div>
<a href="{{ $mg->url('customers') }}" class="btn btn-s hide-m">{{ $c['cta'] }}</a>
</div>

{{-- Desktop: one featured story and the supporting stories. --}}
<div class="hide-m" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 520px), 1fr)); gap: 28px; align-items: start">
@if ($f = $t['featured'])
<article style="display: flex; flex-direction: column; gap: 18px">
@include('marketing.partials.video', ['s' => $f, 'size' => 'lg'])
<div style="display: flex; flex-direction: column; gap: 8px">
@include('marketing.partials.testimonial-meta', ['s' => $f])
@if ($f['quote'])<p style="margin: 0; font-size: 22px; line-height: 1.4; font-weight: 600; letter-spacing: -0.01em">“{{ $f['quote'] }}”</p>@endif
<span style="font-size: 15px; color: #5E5652"><b style="color: #181413">{{ $f['customerName'] }}</b>@if ($byline($f)) · {{ $byline($f) }}@endif</span>
</div>
</article>
@endif
@if ($t['supporting'])
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 22px">
@foreach ($t['supporting'] as $s)
<article style="display: flex; flex-direction: column; gap: 10px">
@include('marketing.partials.video', ['s' => $s, 'size' => 'sm'])
@include('marketing.partials.testimonial-meta', ['s' => $s])
<b style="font-size: 15px">{{ $s['customerName'] }}</b>
@if ($byline($s))<span style="font-size: 13.5px; color: #5E5652">{{ $byline($s) }}</span>@endif
</article>
@endforeach
</div>
@endif
</div>

{{-- Phone: horizontal scroll-snap row holding every story (Home-phone.dc.html). --}}
<div class="show-m">
<div class="scroller">
@foreach ($t['all'] as $s)
<article style="width: 300px; display: flex; flex-direction: column; gap: 10px">
@include('marketing.partials.video', ['s' => $s, 'size' => 'mob'])
@include('marketing.partials.testimonial-meta', ['s' => $s])
<b style="font-size: 16px">{{ $s['customerName'] }}</b>
@if ($byline($s))<span style="font-size: 13.5px; color: #5E5652">{{ $byline($s) }}</span>@endif
</article>
@endforeach
</div>
<span style="display: block; margin-top: 12px; font-size: 13px; color: #6B625E">Swipe for all {{ count($t['all']) }} stories</span>
</div>

@if ($t['hasJazmin'])
<p style="margin: 0; font-size: 13px; color: #6B625E; line-height: 1.5; max-width: 820px">{{ $mg->get('copy.jazmin_disclaimer_home') }}</p>
@endif
@if ($t['hasUnspecified'])
<p style="margin: 0; font-size: 13px; color: #6B625E; line-height: 1.5; max-width: 820px">{{ $mg->get('copy.unspecified_disclaimer') }}</p>
@endif
</div>
</section>
@endif
