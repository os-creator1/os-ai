@php($c = $mg->get('copy.home.how'))
<section class="sec" aria-labelledby="how-h">
<div class="wrap" style="display: flex; flex-direction: column; gap: 56px">
<div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 24px">
<div style="display: flex; flex-direction: column; gap: 18px; max-width: 680px"><span class="eyebrow">{{ $c['eyebrow'] }}</span><h2 id="how-h" class="h2">{{ $c['title'] }}</h2></div>
<a href="{{ $mg->url('how-it-works') }}" class="btn btn-s hide-m">{{ $c['cta'] }}</a>
</div>
<ol class="hide-m" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px">
@foreach ($mg->get('how_steps') as $i => [$title, $text, $milestone])
@php($last = $loop->last)
<li class="card" style="padding: 28px; display: flex; flex-direction: column; gap: 14px;{{ $last ? ' background: #181413; color: #FFFFFF; border-color: #181413' : '' }}"><span style="width: 44px; height: 44px; border-radius: 12px; background: {{ $last ? '#B4514A' : '#181413' }}; color: #FFFFFF; font-weight: 800; display: flex; align-items: center; justify-content: center">{{ $i + 1 }}</span><b style="font-size: 19px; letter-spacing: -0.01em">{{ $title }}</b><span style="color: {{ $last ? '#C9C0BA' : '#5E5652' }}; line-height: 1.55">{{ $text }}</span><span class="chip" style="background: {{ $last ? '#2E2826' : '#F3EFEB' }}; color: {{ $last ? '#E9E2DC' : '#4A4340' }}; align-self: flex-start; margin-top: auto">{{ $milestone }}</span></li>
@endforeach
</ol>
{{-- Phone: a compact version of the same four steps. --}}
<div class="card show-m" style="overflow: hidden; border-radius: 18px">
@foreach ($mg->get('how_steps') as $i => [$title, $text])
<div style="display: flex; gap: 14px; padding: 16px 18px;{{ $loop->last ? '' : ' border-bottom: 1px solid #EFEAE5' }}"><span style="width: 28px; height: 28px; flex: none; border-radius: 8px; background: #181413; color: #FFFFFF; font-weight: 800; font-size: 13px; display: flex; align-items: center; justify-content: center">{{ $i + 1 }}</span><div><b>{{ $title }}</b><div style="color: #5E5652; font-size: 14.5px; margin-top: 4px">{{ $text }}</div></div></div>
@endforeach
</div>
<a href="{{ $mg->url('how-it-works') }}" class="show-m" style="font-weight: 600; color: #8F3B35; text-decoration: underline">{{ $c['cta'] }}</a>
</div>
</section>
