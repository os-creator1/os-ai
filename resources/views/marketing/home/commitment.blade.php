@php($c = $mg->get('copy.home.commitment'))
<section class="sec hide-m" aria-labelledby="com-h">
<div class="wrap">
<div style="border-radius: 28px; background: #FFFFFF; border: 1px solid #E7E1DB; padding: clamp(32px, 5vw, 64px); display: flex; flex-direction: column; gap: 40px">
<div style="display: flex; flex-wrap: wrap; gap: 40px; align-items: flex-end; justify-content: space-between">
<div style="display: flex; flex-direction: column; gap: 18px; max-width: 680px"><span class="eyebrow" style="color: #1F5E4A">{{ $c['eyebrow'] }}</span><h2 id="com-h" class="h2">{{ $c['title'] }}</h2><p class="lead">{{ $c['lead'] }}</p></div>
<span aria-hidden="true" style="width: 96px; height: 96px; border-radius: 28px; background: #E4F0EA; color: #1F5E4A; display: flex; align-items: center; justify-content: center"><svg class="ic" viewBox="0 0 24 24" style="width: 44px; height: 44px; stroke-width: 1.5"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"></path><path d="M8.5 12l2.5 2.5 4.5-5"></path></svg></span>
</div>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px">
@foreach ($c['cards'] as [$title, $text])
<div style="background: #F7F5F2; border-radius: 18px; padding: 24px; display: flex; flex-direction: column; gap: 12px"><b style="font-size: 16px">{{ $title }}</b><span style="color: #4A4340; line-height: 1.6">{{ $text }}</span></div>
@endforeach
</div>
</div>
</div>
</section>
