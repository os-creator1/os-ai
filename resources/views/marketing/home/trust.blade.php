@php
    $t = $mg->get('copy.home.trust');
    $logos = $mg->section('home.trust_logos') ? (array) $mg->get('trust_logos', []) : [];
@endphp
<section class="trust" aria-labelledby="trust-h">
<div class="wrap trust-wrap">
<h2 id="trust-h" class="trust-h"><span class="hide-m">{{ $t['title'] }}</span><span class="show-m">{{ $t['title_mobile'] }}</span></h2>
<ul class="trust-chips hide-m" style="list-style: none; margin: 0; padding: 0">
@foreach ($t['industries'] as $name)
<li class="chip" style="height: 40px; padding: 0 16px; font-size: 14.5px; background: #FFFFFF; border: 1px solid #E7E1DB">{{ $name }}</li>
@endforeach
</ul>
<ul class="scroller show-m" style="list-style: none; margin-top: 0; margin-bottom: 0">
@foreach ($t['industries_mobile'] as $name)
<li class="chip" style="height: 38px; padding: 0 14px; font-size: 14px; background: #FFFFFF; border: 1px solid #E7E1DB">{{ $name }}</li>
@endforeach
</ul>
@if ($logos)
<ul class="trust-logos" style="list-style: none; margin: 0; padding: 10px 0 0">
@foreach ($logos as $logo)
<li style="height: 72px; border: 1px solid #E7E1DB; border-radius: 14px; background: #FFFFFF; display: flex; align-items: center; justify-content: center; padding: 12px"><img src="{{ $mg->asset($logo['image']) }}" alt="{{ $logo['name'] }}" style="max-height: 100%; max-width: 100%; object-fit: contain"></li>
@endforeach
</ul>
<p style="margin: 0; font-size: 12.5px; color: #6B625E">Logos are shown with each customer’s permission.</p>
@endif
</div>
</section>
