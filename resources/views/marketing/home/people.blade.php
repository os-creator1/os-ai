@php
    $p = $mg->get('copy.home.people');
    $specialist = (array) $mg->get('setup_specialist');
    $specialistName = filled($specialist['name'] ?? null) ? $specialist['name'] : ($mg->showPlaceholders() ? '[Setup specialist name]' : null);
    $showGuide = $mg->section('home.setup_specialist_card') && $specialistName !== null;
@endphp
<section class="sec" aria-labelledby="ppl-h" style="background: #EDE5DF">
<div class="wrap" style="display: flex; flex-wrap: wrap; gap: 64px; align-items: center">
<div style="flex: 1 1 460px; display: flex; flex-direction: column; gap: 22px">
<span class="eyebrow"><span class="hide-m">{{ $p['eyebrow'] }}</span><span class="show-m">{{ $p['eyebrow_mobile'] }}</span></span>
<h2 id="ppl-h" class="h2">{{ $p['title'] }}</h2>
<p class="lead hide-m">{{ $p['lead'] }}</p>
<p class="lead show-m">{{ $p['lead_mobile'] }}</p>
<ul class="hide-m" style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 14px; font-size: 16.5px">
@foreach ($p['points'] as [$strong, $rest])
<li style="display: flex; gap: 12px; align-items: flex-start"><span class="tick" style="margin-top: 1px"><svg class="ic" viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke-width: 2.6"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span><span><b>{{ $strong }}</b>{{ str_starts_with($rest, ',') ? '' : ' ' }}{{ $rest }}</span></li>
@endforeach
</ul>
<p class="hide-m" style="margin: 0; font-size: 13.5px; color: #5E5652; line-height: 1.5">{{ $p['note'] }}</p>
</div>
<div aria-hidden="true" class="ui hide-m" style="flex: 1 1 440px; display: flex; flex-direction: column; gap: 14px">
@include('marketing.home._people-checklist')
@if ($showGuide)
<div class="card" style="padding: 16px 18px; display: flex; gap: 14px; align-items: flex-start; margin-left: 40px">
@if (filled($specialist['photo'] ?? null))
<img src="{{ $mg->asset($specialist['photo']) }}" alt="" style="width: 44px; height: 44px; flex: none; border-radius: 50%; object-fit: cover">
@else
<div style="width: 44px; height: 44px; flex: none; border-radius: 50%; border: 1.5px dashed #C9BDB5; display: flex; align-items: center; justify-content: center; font-size: 9px; color: #6B625E; text-align: center">[Photo]</div>
@endif
<div style="display: flex; flex-direction: column; gap: 4px"><b style="font-size: 13.5px">{{ $specialistName }} <span style="font-weight: 500; color: #6B625E">· {{ $mg->brandName() }} team</span></b><span style="font-size: 13.5px; line-height: 1.5; color: #2E2826">Your calendar is connected. Next we’ll set up your business number — I’ve booked 15 minutes for Thursday.</span><span style="font-size: 11.5px; color: #6B625E">Example message</span></div>
</div>
@endif
</div>
<div class="card m-setup show-m" style="width: 100%; padding: 18px; display: flex; flex-direction: column; gap: 2px; font-size: 14.5px; border-radius: 18px">
<div style="display: flex; justify-content: space-between; padding-bottom: 10px"><b>Your setup</b><span class="chip" style="background: #E4F0EA; color: #1F5E4A">4 of 6</span></div>
<div style="padding: 10px 0; border-top: 1px solid #F0EBE6; display: flex; justify-content: space-between"><span>Website published</span><span style="color: #1F5E4A; font-weight: 600">Done</span></div>
<div style="padding: 10px 0; border-top: 1px solid #F0EBE6; display: flex; justify-content: space-between"><span>Calendar connected</span><span style="color: #1F5E4A; font-weight: 600">Done</span></div>
<div style="padding: 10px 0; border-top: 1px solid #F0EBE6; display: flex; justify-content: space-between"><span>Business number</span><span style="color: #7A4A0A; font-weight: 600">With your guide</span></div>
<div style="padding-top: 8px; font-size: 12px; color: #6B625E">Example setup checklist</div>
</div>
</div>
</section>
