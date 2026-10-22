{{-- Dark closing panel. $title, optional $lead, $buttons = [['label','url','style' => 'btn-p'|'ghost'|'btn-s'], ...] --}}
<section style="padding: 0 0 112px"><div class="wrap"><div style="border-radius: 28px; background: #181413; color: #FFFFFF; padding: 56px; display: flex; flex-wrap: wrap; gap: 24px; align-items: center; justify-content: space-between" class="cta-panel">
<div style="display: flex; flex-direction: column; gap: 10px; max-width: 620px"><h2 style="margin: 0; font-size: clamp(28px, 3vw, 40px); font-weight: 800; letter-spacing: -0.03em">{{ $title }}</h2>@if (! empty($lead))<p style="margin: 0; color: #C9C0BA; font-size: 17px">{{ $lead }}</p>@endif</div>
<div style="display: flex; flex-wrap: wrap; gap: 12px">@foreach ($buttons as $b)<a href="{{ $b['url'] }}" class="btn {{ $b['style'] === 'ghost' ? '' : $b['style'] }}"@if ($b['style'] === 'ghost') style="border-color: rgba(255,255,255,.35); color: #FFFFFF"@endif>{{ $b['label'] }}</a>@endforeach</div>
</div></div></section>
