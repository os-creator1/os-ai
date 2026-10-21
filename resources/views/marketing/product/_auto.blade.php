@php($f = $mg->feature('auto'))
<section id="auto" class="mod" aria-labelledby="m3">
<div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 16px"><span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">{{ $f['chip'] }}</span><h2 id="m3" class="h2">{{ $f['title'] }}</h2><p class="lead">{{ $f['lead'] }}</p>
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px">@foreach ($f['bullets'] as $bullet)<li class="li">{{ $bullet }}</li>@endforeach</ul></div>
<div aria-hidden="true" class="ui" style="flex: 1 1 460px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px">
<div class="card" style="padding: 16px"><b>New inquiry → reply</b><div style="color: #6B625E; margin-top: 4px">Text + email in 2 min</div><span class="chip" style="background: #E4F0EA; color: #1F5E4A; margin-top: 10px">On</span></div>
<div class="card" style="padding: 16px"><b>Booking reminder</b><div style="color: #6B625E; margin-top: 4px">24h and 2h before</div><span class="chip" style="background: #E4F0EA; color: #1F5E4A; margin-top: 10px">On</span></div>
<div class="card" style="padding: 16px"><b>Signed → payment</b><div style="color: #6B625E; margin-top: 4px">Request deposit</div><span class="chip" style="background: #E4F0EA; color: #1F5E4A; margin-top: 10px">On</span></div>
@if ($mg->get('show_unverified_features'))<div class="card" style="padding: 16px"><b>Review request</b><div style="color: #6B625E; margin-top: 4px">Day after the event</div><span class="chip" style="background: #EFEBE7; color: #4A4340; margin-top: 10px">Off</span></div>@endif
<div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
</section>
