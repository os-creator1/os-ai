@php($c = $mg->get('copy.home.how'))
<section class="sec hide-m" aria-labelledby="how-h">
<div class="wrap" style="display: flex; flex-direction: column; gap: 56px">
<div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 24px">
<div style="display: flex; flex-direction: column; gap: 18px; max-width: 680px"><span class="eyebrow">{{ $c['eyebrow'] }}</span><h2 id="how-h" class="h2">{{ $c['title'] }}</h2></div>
<a href="{{ $mg->url('how-it-works') }}" class="btn btn-s">{{ $c['cta'] }}</a>
</div>
<ol style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px">
@foreach ($mg->get('how_steps') as $i => [$title, $text, $milestone])
@php($last = $loop->last)
<li class="card" style="padding: 28px; display: flex; flex-direction: column; gap: 14px;{{ $last ? ' background: #181413; color: #FFFFFF; border-color: #181413' : '' }}"><span style="width: 44px; height: 44px; border-radius: 12px; background: {{ $last ? '#B4514A' : '#181413' }}; color: #FFFFFF; font-weight: 800; display: flex; align-items: center; justify-content: center">{{ $i + 1 }}</span><b style="font-size: 19px; letter-spacing: -0.01em">{{ $title }}</b><span style="color: {{ $last ? '#C9C0BA' : '#5E5652' }}; line-height: 1.55">{{ $text }}</span><span class="chip" style="background: {{ $last ? '#2E2826' : '#F3EFEB' }}; color: {{ $last ? '#E9E2DC' : '#4A4340' }}; align-self: flex-start; margin-top: auto">{{ $milestone }}</span></li>
@endforeach
</ol>
</div>
</section>
