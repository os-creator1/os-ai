@php($c = $mg->get('copy.home.final'))
<section aria-labelledby="cta-h" class="final-cta" style="padding: 0 0 120px">
<div class="wrap">
<div class="final-box" style="border-radius: 32px; background: #B4514A; color: #FFFFFF; padding: clamp(48px, 7vw, 96px) clamp(28px, 5vw, 72px); display: flex; flex-direction: column; align-items: center; text-align: center; gap: 24px; position: relative; overflow: hidden">
<img src="{{ $mg->asset('img/mg-mark.png') }}" alt="" aria-hidden="true" class="hide-m" style="position: absolute; right: -60px; bottom: -80px; width: 340px; height: 340px; opacity: 0.12; filter: brightness(2)">
<h2 id="cta-h" style="margin: 0; font-size: clamp(36px, 4.6vw, 60px); line-height: 1.04; font-weight: 800; letter-spacing: -0.035em; max-width: 880px">{{ $c['title'] }}</h2>
<p class="final-lead" style="margin: 0; font-size: 19px; line-height: 1.55; color: #FBE9E6; max-width: 620px"><span class="hide-m">{{ $c['lead'] }}</span><span class="show-m">{{ $c['lead_mobile'] }}</span></p>
<div class="final-cta-btns" style="display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; padding-top: 6px">
<a href="{{ $mg->getStartedUrl() }}" class="btn" style="background: #FFFFFF; color: #181413; min-height: 54px; padding: 0 28px; font-size: 16px">{{ $c['primary'] }}</a>
@if ($mg->hasBookDemo())<a href="{{ $mg->bookDemoUrl() }}" class="btn btn-g" style="min-height: 54px; padding: 0 26px; font-size: 16px">{{ $c['secondary'] }}</a>@endif
</div>
</div>
</div>
</section>
