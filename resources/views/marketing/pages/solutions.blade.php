@extends('marketing.layout')

@section('content')
@php
    $c = $mg->get('page_copy.solutions');
    $industries = $mg->industries();
    $ag = $c['agency'];
    $showUnverified = (bool) $mg->get('show_unverified_features');
@endphp
<section style="padding: 96px 0 64px">
<div class="wrap" style="display: flex; flex-direction: column; gap: 22px; max-width: 1200px">
<span class="eyebrow">{{ $c['eyebrow'] }}</span>
<h1 style="margin: 0; font-size: clamp(44px, 6vw, 76px); line-height: 1; font-weight: 800; letter-spacing: -0.045em; max-width: 960px">{{ $c['title'] }}</h1>
<p class="lead" style="max-width: 680px">{{ $c['lead'] }}</p>
</div>
</section>

<section aria-labelledby="ind-h" style="padding: 0 0 112px">
<div class="wrap" style="display: flex; flex-direction: column; gap: 28px" data-tabs>
<h2 id="ind-h" class="sr-only">Industries</h2>
<div role="group" aria-label="Choose an industry" style="display: flex; flex-wrap: wrap; gap: 8px">
@foreach ($industries as $i => $industry)
<button type="button" class="pill" data-tab="{{ $i + 1 }}" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}" style="height: 46px; padding: 0 18px; font-size: 15px">{{ $industry['name'] }}</button>
@endforeach
</div>
@foreach ($industries as $i => $industry)
<div data-panel="{{ $i + 1 }}" class="{{ $i === 0 ? 'is-on' : '' }}" style="display: flex; flex-wrap: wrap; gap: 32px; padding: 40px; border-radius: 28px; background: #FFFFFF; border: 1px solid #E7E1DB">
<div style="flex: 1 1 380px; display: flex; flex-direction: column; gap: 16px">
<span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">{{ $industry['name'] }}</span>
<h3 style="margin: 0; font-size: 34px; line-height: 1.1; font-weight: 800; letter-spacing: -0.03em">{{ $industry['head'] }}</h3>
<p class="lead">{{ $industry['body'] }}</p>
<div style="display: flex; flex-wrap: wrap; gap: 10px; padding-top: 6px">@if ($mg->hasBookDemo())<a href="{{ $mg->bookDemoUrl() }}" class="btn btn-p">Book a demo</a>@endif<a href="{{ $mg->url('pricing') }}" class="btn {{ $mg->hasBookDemo() ? 'btn-s' : 'btn-p' }}">See pricing</a></div>
</div>
<div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 10px">
<span style="font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #6B625E">What MotionGrove takes off your plate</span>
@foreach ($industry['jobs'] as $job)
<div style="display: flex; gap: 14px; align-items: flex-start; padding: 16px 18px; border-radius: 14px; background: #F7F5F2"><span style="width: 26px; height: 26px; flex: none; border-radius: 50%; background: #E4F0EA; color: #1F5E4A; display: flex; align-items: center; justify-content: center"><svg viewBox="0 0 24 24" aria-hidden="true" style="width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span><div><b style="font-size: 15.5px">{{ $job[0] }}</b><div style="color: #5E5652; font-size: 14.5px; margin-top: 2px; line-height: 1.5">{{ $job[1] }}</div></div></div>
@endforeach
</div>
</div>
@endforeach
<p style="margin: 0; font-size: 13px; color: #6B625E">{{ $c['note'] }}@if ($mg->hasBookDemo()) <a href="{{ $mg->bookDemoUrl() }}" style="text-decoration: underline">Ask us</a> — the platform isn’t industry-specific.@endif</p>
</div>
</section>

<section aria-labelledby="ag-h" style="padding: 112px 0; background: #181413; color: #FFFFFF">
<div class="wrap" style="display: flex; flex-wrap: wrap; gap: 56px; align-items: center">
<div style="flex: 1 1 440px; display: flex; flex-direction: column; gap: 18px"><span class="eyebrow" style="color: #E8A49C">{{ $ag['eyebrow'] }}</span><h2 id="ag-h" class="h2">{{ $ag['title'] }}</h2><p class="lead" style="color: #C9C0BA">{{ $ag['lead'] }}</p><div style="display: flex; flex-wrap: wrap; gap: 12px"><a href="{{ $mg->planUrl('agency') }}" class="btn btn-p">{{ $ag['primary'] }}</a>@if ($mg->hasBookDemo())<a href="{{ $mg->bookDemoUrl() }}" class="btn" style="border-color: rgba(255,255,255,.35); color: #FFFFFF">{{ $ag['secondary'] }}</a>@endif</div></div>
<div aria-hidden="true" style="flex: 1 1 440px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; font-size: 13px">
@foreach ($ag['tiles'] as $tile)
@if ($showUnverified || empty($tile['unverified']))
<div style="background: #221C1A; border: 1px solid #3A322F; border-radius: 16px; padding: 18px"><b style="font-size: 15px">{{ $tile[0] }}</b><div style="color: #A99E96; margin-top: 6px">{{ $tile[1] }}</div></div>
@endif
@endforeach
</div>
</div>
</section>

@include('marketing.partials.cta-center', ['title' => $c['cta']['title'], 'lead' => $c['cta']['lead'], 'titleMax' => 760, 'leadMax' => 560])
@endsection

@section('footer')
@include('marketing.partials.footer-compact')
@endsection
