@php($p = $mg->get('copy.home.problem'))
<section class="sec" aria-labelledby="prob-h">
<div class="wrap prob-wrap" style="display: flex; flex-direction: column; gap: 64px">
<div style="display: flex; flex-wrap: wrap; gap: 56px; align-items: center">
<div style="flex: 1 1 460px; display: flex; flex-direction: column; gap: 22px">
<span class="eyebrow">{{ $p['eyebrow'] }}</span>
<h2 id="prob-h" class="h2">{{ $p['title'] }}</h2>
@foreach ($p['lead'] as $para)
<p class="lead hide-m">{{ $para }}</p>
@endforeach
<p class="lead show-m">{{ $p['lead_mobile'] }}</p>
</div>
<div class="hide-m" style="flex: 1 1 420px; display: flex">@include('marketing.home._problem-collage')</div>
</div>
<ul class="hide-m" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1px; background: #E7E1DB; border: 1px solid #E7E1DB; border-radius: 20px; overflow: hidden">
@foreach ($p['items'] as $item)
<li style="background: #FFFFFF; padding: 26px 28px; display: flex; flex-direction: column; gap: 8px"><b style="font-size: 17px">{{ $item[0] }}</b><span style="color: #5E5652; line-height: 1.55">{{ $item[1] }}</span></li>
@endforeach
</ul>
@php($short = array_values(array_filter($p['items'], fn ($i) => ! empty($i[2]))))
<div class="card show-m" style="overflow: hidden; border-radius: 18px">
@foreach ($short as $i => $item)
<div style="padding: 16px 18px;{{ $i < count($short) - 1 ? ' border-bottom: 1px solid #EFEAE5' : '' }}"><b>{{ $item[3] ?? $item[0] }}</b><div style="color: #5E5652; font-size: 14.5px; margin-top: 4px">{{ $item[2] }}</div></div>
@endforeach
</div>
<p class="prob-close" style="margin: 0; text-align: center; font-weight: 700; letter-spacing: -0.02em"><span class="hide-m">{{ $p['closing'] }} </span><span style="color: #B4514A">{{ $p['closing_accent'] }}</span></p>
</div>
</section>
