@php
    $s = $mg->get('copy.home.system');
    $steps = $mg->get('flow_steps');
@endphp
<section class="sec" aria-labelledby="sys-h" style="background: #181413; color: #FFFFFF">
<div class="wrap" style="display: flex; flex-direction: column; gap: 56px" data-tabs>
<div style="display: flex; flex-direction: column; gap: 18px; max-width: 760px">
<span class="eyebrow" style="color: #E8A49C"><span class="hide-m">{{ $s['eyebrow'] }}</span><span class="show-m">{{ $s['eyebrow_mobile'] }}</span></span>
<h2 id="sys-h" class="h2"><span class="hide-m">{{ $s['title'] }}</span><span class="show-m">{{ $s['title_mobile'] }}</span></h2>
<p class="lead hide-m" style="color: #C9C0BA">{{ $s['lead'] }}</p>
</div>

{{-- Desktop: choose a step to see what happens. All panels are in the HTML; JavaScript only toggles which one shows. --}}
<ol class="hide-m" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px">
@foreach ($steps as $i => $step)
<li style="display: flex"><button type="button" class="flow-btn" data-tab="{{ $i + 1 }}" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}">
<span style="display: flex; align-items: center; justify-content: space-between; width: 100%"><span class="fn">0{{ $i + 1 }}</span><span class="fd"></span></span>
<span style="font-size: 17px; font-weight: 700; letter-spacing: -0.01em">{{ $step['title'] }}</span>
<span style="font-size: 13px; color: #A99E96">{{ $step['tool'] }}</span>
</button></li>
@endforeach
</ol>
<div class="hide-m">
@foreach ($steps as $i => $step)
<div data-panel="{{ $i + 1 }}" class="{{ $i === 0 ? 'is-on' : '' }}" style="display: flex; flex-wrap: wrap; gap: 40px; align-items: center; padding: 36px; border-radius: 24px; background: #221C1A; border: 1px solid #3A322F">
<div style="flex: 1 1 380px; display: flex; flex-direction: column; gap: 14px">
<span style="font-size: 13px; font-weight: 700; color: #E8A49C">Step 0{{ $i + 1 }} · {{ $step['tool'] }}</span>
<h3 style="margin: 0; font-size: 30px; font-weight: 800; letter-spacing: -0.025em">{{ $step['head'] }}</h3>
<p style="margin: 0; font-size: 17px; line-height: 1.6; color: #C9C0BA">{{ $step['body'] }}</p>
</div>
<div aria-hidden="true" class="ui" style="flex: 1 1 380px; background: #FFFFFF; color: #181413; border-radius: 18px; padding: 20px; display: flex; flex-direction: column; gap: 10px">
<span style="font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #6B625E">{{ $step['ui_label'] }}</span>
@foreach ($step['rows'] as [$label, $meta, $color])
<div style="display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 12px; background: #F7F5F2"><span style="width: 8px; height: 8px; border-radius: 50%; background: {{ $color }}"></span><b style="flex: 1; font-size: 13.5px">{{ $label }}</b><span style="color: #6B625E">{{ $meta }}</span></div>
@endforeach
</div>
</div>
@endforeach
</div>

{{-- Phone: the same six steps as a timeline (Home-phone.dc.html). --}}
<ol class="show-m" style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column">
@foreach ($steps as $i => $step)
<li style="display: flex; gap: 16px"><div style="display: flex; flex-direction: column; align-items: center"><span style="width: 36px; height: 36px; border-radius: 50%; background: {{ $i === 0 ? '#B4514A' : '#2E2826' }}; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px">{{ $i + 1 }}</span>@if (! $loop->last)<span style="flex: 1; width: 2px; background: #3A322F; min-height: 24px"></span>@endif</div><div style="{{ $loop->last ? '' : 'padding-bottom: 22px' }}"><b style="font-size: 17px">{{ $step['title'] }}</b><div style="color: #C9C0BA; font-size: 14.5px; margin-top: 4px">{{ $step['short'] }}</div></div></li>
@endforeach
</ol>
</div>
</section>
