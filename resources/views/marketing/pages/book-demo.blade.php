@extends('marketing.layout')

{{-- Receives the booking engine's data (type, timezone, timezones, config) plus $formFields, $formVersion and $demo. --}}
@push('head')
@include('public.booking._scheduler-styles')
<link rel="stylesheet" href="{{ $mg->asset('css/book-demo.css') }}">
@endpush

@section('content')
@php
    $minutes = (int) $type->duration_minutes;
    $steps = [
        ['Your current setup', 'Which tools you use and where things slip.'],
        ['A walkthrough for your business', 'Website, CRM, follow-ups and bookings, in your context.'],
        ['The right plan and next steps', 'What setup would look like and how long it takes.'],
    ];
    $supportUrl = $mg->helpUrl();
    $contactUrl = $mg->link('support_email');
    $privacyUrl = $mg->link('privacy');
    // An owner may publish a form with no questions: the page then goes straight to choosing a time.
    $hasForm = count($formFields) > 0;
@endphp
<section style="padding: 88px 0 112px">
<div class="wrap" style="display: flex; flex-wrap: wrap; gap: 64px; align-items: flex-start">
<div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 24px">
<span class="eyebrow">Book a demo</span>
<h1 style="margin: 0; font-size: clamp(42px, 5.4vw, 68px); line-height: 1; font-weight: 800; letter-spacing: -0.045em">Let’s talk about your business.</h1>
<p class="lead">A relaxed call with a real person. We’ll look at how you handle inquiries and bookings today, and show you how MotionGrove would fit — no pressure to buy.</p>
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 18px; font-size: 16px; line-height: 1.5">
@foreach ($steps as $n => [$title, $text])
<li class="li"><span style="width: 36px; height: 36px; flex: none; border-radius: 10px; background: #F6E7E4; color: #8F3B35; display: flex; align-items: center; justify-content: center; font-weight: 800">{{ $n + 1 }}</span><span><b>{{ $title }}</b><br><span style="color: #5E5652">{{ $text }}</span></span></li>
@endforeach
</ul>
<div style="display: flex; gap: 14px; align-items: center; padding: 18px 20px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E7E1DB">
<span style="width: 44px; height: 44px; flex: none; border-radius: 12px; background: #E4F0EA; color: #1F5E4A; display: flex; align-items: center; justify-content: center"><svg viewBox="0 0 24 24" aria-hidden="true" style="width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round"><circle cx="12" cy="12" r="8.5"></circle><path d="M12 7.5V12l3 2"></path></svg></span>
<span style="font-size: 15px; line-height: 1.5"><b>{{ $minutes }} minutes</b> · online video call</span>
</div>
</div>

<div class="mgd-card" id="mgd-card" data-state="{{ $hasForm ? 'form' : 'schedule' }}" data-intro-minutes="{{ $minutes }}">
@if ($hasForm)
<form id="mgd-form" method="post" action="{{ $demo['detailsUrl'] }}" style="display: flex; flex-direction: column; gap: 18px" aria-labelledby="form-h">
@csrf
<h2 id="form-h" style="margin: 0; font-size: 22px; font-weight: 700">Tell us a little about you</h2>
<p class="mgd-alert" id="mgd-alert" role="alert" hidden></p>
@include('marketing.book-demo._fields', ['fields' => $formFields])
<button type="submit" class="btn btn-p" id="mgd-continue" style="min-height: 54px; font-size: 16px">Continue to choose a time</button>
<p style="margin: 0; font-size: 12.5px; color: #6B625E; line-height: 1.5">We’ll only use your details to arrange this call.@if ($privacyUrl) See our <a href="{{ $privacyUrl }}" style="text-decoration: underline">Privacy Policy</a>.@endif</p>
</form>
@endif

<div id="mgd-schedule" @if ($hasForm) hidden @endif>
@if ($hasForm)
<button type="button" class="mgd-edit" id="mgd-edit"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="18" height="18"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg>Edit my details</button>
@endif
<div class="mgd-thanks" id="mgd-thanks" role="status" tabindex="-1">
@if ($hasForm)
<span style="width: 56px; height: 56px; border-radius: 16px; background: #E4F0EA; color: #1F5E4A; display: flex; align-items: center; justify-content: center"><svg viewBox="0 0 24 24" aria-hidden="true" style="width: 26px; height: 26px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>
@endif
<h2 style="margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -0.02em">{{ $hasForm ? 'Thanks — now pick a time.' : 'Pick a time that suits you.' }}</h2>
<p style="margin: 0; color: #4A4340; line-height: 1.6">Choose a time for your {{ $minutes }}-minute demo. Times are shown in <span id="pb-tz-label">{{ $timezone }}</span>.</p>
</div>
@include('marketing.book-demo._scheduler')
</div>
</div>
</div>
</section>

<section aria-labelledby="ct-h" style="padding: 0 0 112px">
<div class="wrap" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px">
<h2 id="ct-h" style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)">Other ways to reach us</h2>
<div style="background: #FFFFFF; border: 1px solid #E7E1DB; border-radius: 20px; padding: 26px; display: flex; flex-direction: column; gap: 8px"><b style="font-size: 17px">Ready to start?</b><span style="color: #5E5652; line-height: 1.55">Compare plans and sign up without a call.</span><a href="{{ $mg->url('pricing') }}" style="color: #8F3B35; font-weight: 600; margin-top: 4px">See pricing →</a></div>
@if ($supportUrl)
<div style="background: #FFFFFF; border: 1px solid #E7E1DB; border-radius: 20px; padding: 26px; display: flex; flex-direction: column; gap: 8px"><b style="font-size: 17px">Already a customer?</b><span style="color: #5E5652; line-height: 1.55">Get help from our support team.</span><a href="{{ $supportUrl }}" style="color: #8F3B35; font-weight: 600; margin-top: 4px">Get support →</a></div>
@endif
@if ($contactUrl)
<div style="background: #FFFFFF; border: 1px solid #E7E1DB; border-radius: 20px; padding: 26px; display: flex; flex-direction: column; gap: 8px"><b style="font-size: 17px">General questions</b><span style="color: #5E5652; line-height: 1.55">Partnerships, press and everything else.</span><a href="{{ $contactUrl }}" style="color: #8F3B35; font-weight: 600; margin-top: 4px">Email us →</a></div>
@endif
</div>
</section>
@endsection

@section('footer')
@include('marketing.partials.footer-compact')
@if ($demo['turnstileSiteKey'])
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
@endif
@include('public.booking._scheduler-script')
<script src="{{ $mg->asset('js/book-demo.js') }}" defer></script>
@endsection
