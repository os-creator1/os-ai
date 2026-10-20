@php($h = $mg->get('copy.home.hero'))
<section class="hero" aria-labelledby="hero-h" style="overflow: hidden">
<div class="wrap hero-top">
<span class="chip hero-chip" style="background: #FFFFFF; border: 1px solid #E7E1DB; color: #4A4340"><span style="width: 8px; height: 8px; border-radius: 50%; background: #1F5E4A"></span>{{ $h['chip'] }}</span>
<h1 id="hero-h" class="hero-h1">{{ $h['title'] }}<br><span style="color: #B4514A">{{ $h['title_accent'] }}</span></h1>
<p class="lead hero-lead hide-m">{{ $h['lead'] }}</p>
<p class="lead hero-lead show-m">{{ $h['lead_mobile'] }}</p>
<div class="hero-cta">
<a href="{{ $mg->ctaUrl('hero') }}" class="btn btn-p hero-btn">{{ $h['primary'] }}<svg class="ic hide-m" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg></a>
<a href="{{ $mg->url('how-it-works') }}" class="btn btn-s hero-btn">{{ $h['secondary'] }}</a>
</div>
<ul class="hero-ticks" style="list-style: none; margin: 0; padding: 0">
@foreach ($h['ticks'] as $i => $tick)
<li class="{{ $i > 0 ? 'hide-m' : '' }}"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>{{ $tick }}</li>
@endforeach
</ul>
</div>

@if ($shot = $mg->get('screenshots.hero'))
<div class="wrap" style="position: relative; margin-top: 64px; max-width: 1280px"><figure style="margin: 0; position: relative"><img src="{{ $mg->asset($shot) }}" alt="{{ $mg->brandName() }} workspace with sample data" style="width: 100%; height: auto; border-radius: 22px; border: 1px solid #DCD5CE; box-shadow: 0 40px 80px -30px rgba(60,30,24,0.35)"><figcaption style="position: absolute; right: 16px; bottom: 14px"><span class="chip" style="background: #181413; color: #FFFFFF">{{ $mg->get('copy.example_tag') }}</span></figcaption></figure></div>
@else
<div class="hide-m">@include('marketing.home._hero-desktop-shot')</div>
<div class="wrap show-m">@include('marketing.home._hero-phone-shot')</div>
@endif
<div style="height: 96px" class="hide-m"></div>
</section>
