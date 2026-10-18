@extends('marketing.layout')

@section('content')
@php($c = $mg->get('page_copy.how-it-works'))
<section style="padding: 96px 0 72px">
<div class="wrap" style="display: flex; flex-direction: column; gap: 22px">
<span class="eyebrow">{{ $c['eyebrow'] }}</span>
<h1 style="margin: 0; font-size: clamp(44px, 6vw, 76px); line-height: 1; font-weight: 800; letter-spacing: -0.045em; max-width: 900px">{{ $c['title'] }} <span style="color: #B4514A">{{ $c['title_accent'] }}</span></h1>
<p class="lead" style="max-width: 660px">{{ $c['lead'] }}</p>
</div>
</section>

<section aria-label="Steps" style="padding: 0 0 112px">
<div class="wrap">
<ol style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column">
@foreach ($c['steps'] as $i => [$title, $text, $you, $we, $milestoneLabel, $milestone])
@php($last = $loop->last)
<li class="step"><div class="rail"><span class="num"@if ($last) style="background: #B4514A"@endif>{{ $i + 1 }}</span>@unless ($last)<span class="line"></span>@endunless</div><div style="flex: 1;{{ $last ? '' : ' padding-bottom: 56px;' }} display: flex; flex-direction: column; gap: 16px"><h2 style="margin: 8px 0 0; font-size: 30px; font-weight: 800; letter-spacing: -0.025em">{{ $title }}</h2><p class="lead">{{ $text }}</p><div style="display: flex; flex-wrap: wrap; gap: 12px"><div class="col"><b>You</b><span>{{ $you }}</span></div><div class="col"><b>We</b><span>{{ $we }}</span></div><div class="col" style="background: #E4F0EA"><b style="color: #1F5E4A">{{ $milestoneLabel }}</b><span>{{ $milestone }}</span></div></div></div></li>
@endforeach
</ol>
</div>
</section>

@php($k = $c['commitment'])
<section aria-labelledby="inc-h" style="padding: 112px 0; background: #EDE5DF">
<div class="wrap" style="display: flex; flex-wrap: wrap; gap: 56px">
<div style="flex: 1 1 380px; display: flex; flex-direction: column; gap: 18px"><span class="eyebrow" style="color: #1F5E4A">{{ $k['eyebrow'] }}</span><h2 id="inc-h" class="h2">{{ $k['title'] }}</h2><p class="lead">{{ $k['lead'] }}</p></div>
<div style="flex: 1 1 520px; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px">
@foreach ($k['cards'] as [$title, $text])
<div style="background: #FFFFFF; border-radius: 18px; padding: 22px; display: flex; flex-direction: column; gap: 8px"><b>{{ $title }}</b><span style="color: #4A4340; line-height: 1.55">{{ $text }}</span></div>
@endforeach
</div>
</div>
</section>

@include('marketing.partials.cta-center', ['title' => $c['cta']['title'], 'lead' => $c['cta']['lead'], 'titleMax' => 720, 'leadMax' => 520])
@endsection

@section('footer')
@include('marketing.partials.footer-compact')
@endsection
