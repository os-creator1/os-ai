{{--
    One public plan card. $plan from MarketingSite::plans(); $variant = 'home' | 'pricing'.
    Prices come from config('marketing.plans') only (verified against the plan catalog by
    `php artisan marketing:verify-pricing`). There is no trial or private-offer variant, by design.
--}}
@php
    $pricing = $variant === 'pricing';
    $featured = $plan['featured'];
    $pad = $pricing ? '34px' : '32px';
    $gap = $pricing ? '22px' : '20px';
    $priceSize = $pricing ? '56px' : '52px';
    $tag = $pricing ? $plan['tagline_pricing'] : $plan['tagline'];
    $features = $pricing ? $plan['features_pricing'] : $plan['features'];
    $heading = $pricing ? 'h2' : 'h3';
    $hSize = $pricing ? '22px' : '20px';
    $muted = $featured ? '#C9C0BA' : '#5E5652';
@endphp
<article @class(['card' => ! $featured]) style="padding: {{ $pad }}; display: flex; flex-direction: column; gap: {{ $gap }};{{ $featured ? ' border-radius: '.($pricing ? '22px' : '20px').'; background: #181413; color: #FFFFFF; box-shadow: 0 30px 60px -30px rgba(24,20,19,.6); position: relative' : '' }}">
@if ($featured && ! empty($plan['badge']))<span class="chip" style="position: absolute; top: {{ $pricing ? '28px' : '24px' }}; right: {{ $pricing ? '28px' : '24px' }}; background: #B4514A; color: #FFFFFF">{{ $plan['badge'] }}</span>@endif
<div><{{ $heading }} style="margin: 0; font-size: {{ $hSize }}; font-weight: 700">{{ $plan['name'] }}</{{ $heading }}><p style="margin: 6px 0 0; color: {{ $muted }}">{{ $tag }}</p></div>
<div style="display: flex; align-items: baseline; gap: 6px"><span style="font-size: {{ $priceSize }}; font-weight: 800; letter-spacing: -0.04em">${{ $plan['price'] }}</span><span style="color: {{ $muted }}">/ 28 days</span></div>
<a href="{{ $plan['url'] }}" class="btn {{ $featured ? 'btn-p' : 'btn-s' }}">{{ $plan['cta'] }}</a>
<ul style="list-style: none; margin: 0; padding: {{ $pricing ? '20px' : '18px' }} 0 0; border-top: 1px solid {{ $featured ? '#3A322F' : '#EFEAE5' }}; display: flex; flex-direction: column; gap: 12px; font-size: 15px">
@if (! empty($plan['features_lead']))<li style="font-weight: 600">{{ $plan['features_lead'] }}</li>@endif
@foreach ($features as $feature)
<li style="display: flex; gap: 10px"><span class="tick"@if ($featured) style="background: #2D4A3F; color: #9FD3BB"@endif><svg class="ic" viewBox="0 0 24 24" style="width: 12px; height: 12px; stroke-width: 2.6"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>{{ $feature }}</li>
@endforeach
</ul>
</article>
