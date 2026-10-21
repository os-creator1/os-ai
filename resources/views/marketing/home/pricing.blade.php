@php
    $c = $mg->get('copy.home.pricing');
    $plans = $mg->plans();
@endphp
<section class="sec" aria-labelledby="price-h" style="background: #EDE5DF">
<div class="wrap price-wrap" style="display: flex; flex-direction: column; gap: 48px">
<div class="price-head" style="display: flex; flex-direction: column; align-items: center; text-align: center; gap: 16px"><span class="eyebrow">{{ $c['eyebrow'] }}</span><h2 id="price-h" class="h2"><span class="hide-m">{{ $c['title'] }}</span><span class="show-m">{{ $c['title_mobile'] }}</span></h2><p class="lead" style="max-width: 560px"><span class="hide-m">{{ $c['lead'] }}</span><span class="show-m">{{ $c['lead_mobile'] }}</span></p></div>

<div class="hide-m" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; align-items: stretch">
@foreach ($plans as $plan)
@include('marketing.partials.plan-card', ['plan' => $plan, 'variant' => 'home'])
@endforeach
</div>
<p class="hide-m" style="margin: 0; text-align: center; font-size: 14px; color: #5E5652">{{ $mg->get('copy.usage_note') }} <a href="{{ $mg->url('pricing') }}" style="color: #8F3B35; font-weight: 600; text-decoration: underline">{{ $c['link'] }}</a></p>

{{-- Phone: Growth up front, the other two as compact rows (Home-phone.dc.html). --}}
<div class="show-m" style="display: flex; flex-direction: column; gap: 14px">
@php($g = collect($plans)->first(fn ($candidate) => $candidate['featured']) ?? collect($plans)->first())
<article style="padding: 24px; border-radius: 20px; background: #181413; color: #FFFFFF; display: flex; flex-direction: column; gap: 14px; text-align: left">
<div style="display: flex; justify-content: space-between; align-items: center"><b style="font-size: 19px">{{ $g['name'] }}</b>@if (! empty($g['badge']))<span class="chip" style="background: #B4514A; color: #FFFFFF">{{ $g['badge'] }}</span>@endif</div>
@if ($g['price_label'] !== null)<div><span style="font-size: 44px; font-weight: 800; letter-spacing: -0.04em">{{ $g['price_label'] }}</span><span style="color: #C9C0BA"> {{ $g['cycle_label'] }}</span></div>@endif
<span style="color: #C9C0BA; font-size: 15px; line-height: 1.5">{{ $g['tagline_mobile'] }}</span>
<a href="{{ $g['url'] }}" class="btn btn-p" style="min-height: 52px; border-radius: 14px; font-size: 16px">{{ $g['cta'] }}</a>
</article>
@foreach (collect($plans)->reject(fn ($candidate) => $candidate['key'] === $g['key']) as $p)
<a href="{{ $p['url'] }}" class="card" style="padding: 18px 22px; display: flex; justify-content: space-between; align-items: center; border-radius: 18px; text-align: left"><div><b style="font-size: 17px">{{ $p['name'] }}</b><div style="color: #5E5652; font-size: 14px">{{ $p['tagline_mobile'] }}</div></div>@if ($p['price_label'] !== null)<b style="font-size: 22px">{{ $p['price_label'] }}</b>@endif</a>
@endforeach
<a href="{{ $mg->url('pricing') }}" style="font-size: 14px; color: #8F3B35; font-weight: 600; text-decoration: underline">{{ $c['link_mobile'] }}</a>
</div>
</div>
</section>
