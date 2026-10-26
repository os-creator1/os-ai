@php($f = $mg->feature('crm'))
<section id="crm" class="mod" aria-labelledby="m2" style="flex-wrap: wrap-reverse">
<div aria-hidden="true" class="card ui" style="flex: 1 1 460px; padding: 18px; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px">
<div style="display: flex; flex-direction: column; gap: 8px"><b style="color: #6B625E">New · 4</b><div style="background: #F7F5F2; border-radius: 10px; padding: 10px"><b>{{ $mg->demo('crm.p1.name') }}</b><div style="color: #6B625E">{{ $mg->demo('crm.p1.detail') }}</div></div><div style="background: #F7F5F2; border-radius: 10px; padding: 10px"><b>{{ $mg->demo('crm.p2.name') }}</b><div style="color: #6B625E">{{ $mg->demo('crm.p2.detail') }}</div></div></div>
<div style="display: flex; flex-direction: column; gap: 8px"><b style="color: #6B625E">{{ $mg->demo('crm.stage') }} · 3</b><div style="background: #F7F5F2; border-radius: 10px; padding: 10px"><b>{{ $mg->demo('crm.p3.name') }}</b><div style="color: #6B625E">{{ $mg->demo('crm.p3.detail') }}</div></div></div>
<div style="display: flex; flex-direction: column; gap: 8px"><b style="color: #6B625E">Booked · 6</b><div style="background: #E4F0EA; border-radius: 10px; padding: 10px"><b>{{ $mg->demo('crm.p4.name') }}</b><div style="color: #1F5E4A">{{ $mg->demo('crm.p4.detail') }}</div></div></div>
<div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
<div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 16px"><span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">{{ $f['chip'] }}</span><h2 id="m2" class="h2">{{ $f['title'] }}</h2><p class="lead">{{ $f['lead'] }}</p>
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px">@foreach ($f['bullets'] as $bullet)<li class="li">{{ $bullet }}</li>@endforeach</ul></div>
</section>
