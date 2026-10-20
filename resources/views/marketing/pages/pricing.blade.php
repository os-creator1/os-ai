@extends('marketing.layout', ['faqs' => $mg->faqs('pricing')])

@section('content')
@php
    $c = $mg->get('page_copy.pricing');
    $plans = $mg->plans();
    $faqs = $mg->faqs('pricing');
    $tick = '<span class="tick" aria-label="Included"><svg class="ic" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>';
    $tip = 0;
@endphp
<section style="padding: 96px 0 56px">
<div class="wrap" style="display: flex; flex-direction: column; align-items: center; text-align: center; gap: 20px">
<span class="eyebrow">{{ $c['eyebrow'] }}</span>
<h1 style="margin: 0; font-size: clamp(44px, 6vw, 72px); line-height: 1; font-weight: 800; letter-spacing: -0.045em; max-width: 860px">{{ $c['title'] }}</h1>
<p class="lead" style="max-width: 600px">{{ $c['lead'] }}</p>
</div>
</section>

<section aria-label="Plans" style="padding: 0 0 96px">
<div class="wrap" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 310px), 1fr)); gap: 20px; align-items: stretch">
@foreach ($plans as $plan)
@include('marketing.partials.plan-card', ['plan' => $plan, 'variant' => 'pricing'])
@endforeach
</div>
@if ($mg->hasBookDemo())
<div class="wrap" style="padding-top: 20px"><p style="margin: 0; text-align: center; font-size: 14px; color: #5E5652">{{ $c['choose_note'] }} <a href="{{ $mg->bookDemoUrl() }}" style="color: #8F3B35; font-weight: 600; text-decoration: underline">Book a demo</a> and we’ll help you choose.</p></div>
@endif
</section>

<section aria-labelledby="cmp-h" style="padding: 0 0 112px">
<div class="wrap" style="display: flex; flex-direction: column; gap: 28px">
<h2 id="cmp-h" class="h2">{{ $c['compare']['title'] }}</h2>
<div style="overflow-x: auto; border-radius: 22px; border: 1px solid #E7E1DB; background: #FFFFFF">
<table style="width: 100%; min-width: 720px; border-collapse: collapse">
<thead><tr><th scope="col" style="border-top: 0; width: 40%; color: #5E5652; font-size: 13px">Feature</th>@foreach ($plans as $plan)<th scope="col" style="border-top: 0; text-align: center;{{ $plan['featured'] ? ' background: #181413; color: #FFFFFF' : '' }}">{{ $plan['name'] }}</th>@endforeach</tr></thead>
<tbody>
@foreach ($mg->get('plan_comparison') as $group)
<tr><th scope="rowgroup" colspan="4" style="background: #F7F5F2; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; color: #6B625E">{{ $group['group'] }}</th></tr>
@foreach ($group['rows'] as $row)
<tr><th scope="row" style="font-weight: 500">{{ $row[0] }}@if (! empty($row[2]))@php($tip++)<span class="tip"><button type="button" aria-describedby="tip{{ $tip }}">?</button><span id="tip{{ $tip }}" role="tooltip">{{ $row[2] }}</span></span>@endif</th>
@foreach (array_values($row[1]) as $col => $value)
@php($bg = $col === 1 ? ' style="background:#FBF9F7"' : '')
@if ($value === true)<td{!! $bg !!}>{!! $tick !!}</td>
@elseif ($value === 'confirm')<td class="no"{!! $bg !!}>{{ $mg->showPlaceholders() ? '[Confirm]' : 'Ask us' }}</td>
@else<td class="no"{!! $bg !!}>—</td>
@endif
@endforeach
</tr>
@endforeach
@endforeach
<tr><th scope="row" style="font-weight: 500">Plan price</th>@foreach ($plans as $plan)<td style="font-weight: 700;{{ $plan['featured'] ? ' background: #FBF9F7' : '' }}">{{ $plan['price_label'] ?? 'Contact us' }}</td>@endforeach</tr>
<tr><th scope="row" style="font-weight: 500"></th>@foreach ($plans as $plan)<td{!! $plan['featured'] ? ' style="background: #FBF9F7"' : '' !!}><a href="{{ $plan['url'] }}" class="btn {{ $plan['featured'] ? 'btn-p' : 'btn-s' }}" style="min-height: 44px">{{ $plan['cta'] }}</a></td>@endforeach</tr>
</tbody>
</table>
</div>
@if ($mg->showPlaceholders())<p style="margin: 0; font-size: 13px; color: #6B625E">[{{ $c['compare']['note'] }}]</p>@endif
</div>
</section>

@if ($mg->section('pricing.usage') && $mg->get('usage_charges'))
<section id="usage" aria-labelledby="use-h" style="padding: 112px 0; background: #EDE5DF">
<div class="wrap" style="display: flex; flex-wrap: wrap; gap: 56px">
@php($u = $c['usage'])
<div style="flex: 1 1 360px; display: flex; flex-direction: column; gap: 18px"><span class="eyebrow">{{ $u['eyebrow'] }}</span><h2 id="use-h" class="h2">{{ $u['title'] }}</h2><p class="lead">{{ $u['lead'] }}</p>@if ($rates = $mg->link('rates'))<a href="{{ $rates }}" style="font-weight: 600; color: #8F3B35; text-decoration: underline">{{ $u['rates'] }}</a>@endif</div>
<div style="flex: 1 1 520px; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px">
@foreach ($mg->get('usage_charges') as [$title, $text])
<div style="background: #FFFFFF; border-radius: 18px; padding: 22px; display: flex; flex-direction: column; gap: 6px"><b style="font-size: 16px">{{ $title }}</b><span style="color: #4A4340; line-height: 1.55">{{ $text }}</span></div>
@endforeach
</div>
</div>
</section>
@endif

@if ($faqs)
<section aria-labelledby="bfaq-h" style="padding: 112px 0">
<div class="wrap" style="display: flex; flex-wrap: wrap; gap: 56px">
<h2 id="bfaq-h" class="h2" style="flex: 1 1 300px">{{ $c['billing_faq'] }}</h2>
<div style="flex: 2 1 560px; border-top: 1px solid #E2DBD4">
@foreach ($faqs as $f)
@include('marketing.partials.faq-item', ['q' => $f['q'], 'a' => $f['a'], 'size' => 'lg'])
@endforeach
</div>
</div>
</section>
@endif
@endsection

@section('footer')
@include('marketing.partials.footer-compact')
@endsection
