@extends('marketing.layout')

{{--
    White Label "Consultation landing page" template. Every word comes from the site's editable `consultation.*` copy;
    the right-hand card is the real public scheduler (same engine, endpoints and script as /book/{uuid}) for the
    Agency's own booking type. No lead form comes before the calendar: contact details are asked only after a time
    is chosen, because the booking itself needs them.
--}}
@push('head')
@include('public.booking._scheduler-styles')
<link rel="stylesheet" href="{{ $mg->asset('css/book-demo.css') }}">
@endpush

@section('content')
@php
    $c = $mg->consultation();
    $steps = array_values(array_filter((array) ($c['steps'] ?? []), fn ($s) => filled($s[0] ?? null)));
    $minutes = $embed ? (int) $embed['config']['durationMinutes'] : null;
@endphp
<section style="padding: 88px 0 112px" data-role="consultation">
<div class="wrap" style="display: flex; flex-wrap: wrap; gap: 64px; align-items: flex-start">
<div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 24px">
@if (filled($c['eyebrow'] ?? null))<span class="eyebrow">{{ $c['eyebrow'] }}</span>@endif
<h1 style="margin: 0; font-size: clamp(42px, 5.4vw, 68px); line-height: 1; font-weight: 800; letter-spacing: -0.045em">{{ $c['title'] ?? '' }}</h1>
@if (filled($c['lead'] ?? null))<p class="lead">{{ $c['lead'] }}</p>@endif
@if ($steps !== [])
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 18px; font-size: 16px; line-height: 1.5">
@foreach ($steps as $n => $step)
<li class="li"><span style="width: 36px; height: 36px; flex: none; border-radius: 10px; background: #F6E7E4; color: #8F3B35; display: flex; align-items: center; justify-content: center; font-weight: 800">{{ $n + 1 }}</span><span><b>{{ $step[0] }}</b>@if (filled($step[1] ?? null))<br><span style="color: #5E5652">{{ $step[1] }}</span>@endif</span></li>
@endforeach
</ul>
@endif
@if ($minutes)
<div style="display: flex; gap: 14px; align-items: center; padding: 18px 20px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E7E1DB">
<span style="width: 44px; height: 44px; flex: none; border-radius: 12px; background: #E4F0EA; color: #1F5E4A; display: flex; align-items: center; justify-content: center"><svg viewBox="0 0 24 24" aria-hidden="true" style="width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round"><circle cx="12" cy="12" r="8.5"></circle><path d="M12 7.5V12l3 2"></path></svg></span>
<span style="font-size: 15px; line-height: 1.5"><b>{{ $minutes }} minutes</b>@if (filled($c['duration_label'] ?? null)) · {{ $c['duration_label'] }}@endif</span>
</div>
@endif
</div>

<div class="mgd-card" id="book" data-state="schedule">
@if ($embed)
<div class="mgd-thanks" style="margin-bottom: 18px">
<h2 style="margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -0.02em">{{ $c['calendar_title'] ?? '' }}</h2>
@if (filled($c['calendar_lead'] ?? null))<p style="margin: 0; color: #4A4340; line-height: 1.6">{{ $c['calendar_lead'] }}</p>@endif
</div>
@include('agency-site.booking-embed', ['embed' => $embed])
@else
<p role="status" style="margin: 0; color: #4A4340; line-height: 1.6">Booking is not available right now. Please check back soon.</p>
@endif
</div>
</div>
</section>
@endsection

@section('footer')
@include('marketing.partials.footer-compact')
@if ($embed)
@include('public.booking._scheduler-script')
@endif
@endsection
