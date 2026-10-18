@extends('marketing.layout')

@section('content')
@php($c = $mg->get('page_copy.product'))
<section style="padding: 96px 0 56px">
<div class="wrap" style="display: flex; flex-direction: column; gap: 22px; align-items: flex-start">
<span class="eyebrow">{{ $c['eyebrow'] }}</span>
<h1 style="margin: 0; font-size: clamp(44px, 6vw, 76px); line-height: 1; font-weight: 800; letter-spacing: -0.045em; max-width: 960px">{{ $c['title'] }} <span style="color: #B4514A">{{ $c['title_accent'] }}</span></h1>
<p class="lead" style="max-width: 680px">{{ $c['lead'] }}</p>
<nav aria-label="Jump to a feature" style="display: flex; flex-wrap: wrap; gap: 8px; padding-top: 10px">
@foreach ($c['jump'] as [$anchor, $label])
<a href="#{{ $anchor }}" class="chip" style="height: 40px; padding: 0 16px; font-size: 14.5px; background: #FFFFFF; border: 1px solid #E7E1DB">{{ $label }}</a>
@endforeach
</nav>
</div>
</section>

<div class="wrap">
<section id="website" class="mod" aria-labelledby="m1">
<div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 16px"><span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">Website · Growth plan</span><h2 id="m1" class="h2">A website that brings you inquiries.</h2><p class="lead">Answer guided questions and MotionGrove builds a structured, mobile-ready site. Edit pages, photos and forms whenever you like.</p>
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px"><li class="li">AI-assisted pages from your own answers</li><li class="li">Hosting, SSL and your own domain</li><li class="li">Service, package and photo pages</li><li class="li">Forms that create contacts automatically</li></ul></div>
<div aria-hidden="true" class="card ui" style="flex: 1 1 460px; overflow: hidden"><div style="height: 34px; background: #F3EFEB; border-bottom: 1px solid #E7E1DB; display: flex; align-items: center; padding: 0 14px; color: #6B625E">Website · Pages</div><div style="padding: 18px; display: flex; flex-direction: column; gap: 8px"><div style="display: flex; justify-content: space-between; padding: 12px 14px; background: #F7F5F2; border-radius: 10px"><b>Home</b><span class="chip" style="background: #E4F0EA; color: #1F5E4A">Published</span></div><div style="display: flex; justify-content: space-between; padding: 12px 14px; background: #F7F5F2; border-radius: 10px"><b>Weddings</b><span class="chip" style="background: #E4F0EA; color: #1F5E4A">Published</span></div><div style="display: flex; justify-content: space-between; padding: 12px 14px; background: #F7F5F2; border-radius: 10px"><b>Corporate events</b><span class="chip" style="background: #FBEFD9; color: #7A4A0A">Draft</span></div><div style="display: flex; justify-content: space-between; padding: 12px 14px; background: #F7F5F2; border-radius: 10px"><b>Contact &amp; booking</b><span class="chip" style="background: #E4F0EA; color: #1F5E4A">Published</span></div></div><div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
</section>
<section id="crm" class="mod" aria-labelledby="m2" style="flex-wrap: wrap-reverse">
<div aria-hidden="true" class="card ui" style="flex: 1 1 460px; padding: 18px; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px">
<div style="display: flex; flex-direction: column; gap: 8px"><b style="color: #6B625E">New · 4</b><div style="background: #F7F5F2; border-radius: 10px; padding: 10px"><b>Jordan Park</b><div style="color: #6B625E">Wedding</div></div><div style="background: #F7F5F2; border-radius: 10px; padding: 10px"><b>Mia Torres</b><div style="color: #6B625E">Birthday</div></div></div>
<div style="display: flex; flex-direction: column; gap: 8px"><b style="color: #6B625E">Proposal · 3</b><div style="background: #F7F5F2; border-radius: 10px; padding: 10px"><b>Riverside Gala</b><div style="color: #6B625E">Corporate</div></div></div>
<div style="display: flex; flex-direction: column; gap: 8px"><b style="color: #6B625E">Booked · 6</b><div style="background: #E4F0EA; border-radius: 10px; padding: 10px"><b>Chen wedding</b><div style="color: #1F5E4A">Deposit paid</div></div></div>
<div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
<div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 16px"><span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">CRM &amp; conversations · all plans</span><h2 id="m2" class="h2">Know where every customer stands.</h2><p class="lead">Contacts, pipelines and conversations in one place — every text, email and note on the customer’s record.</p>
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px"><li class="li">Pipelines that match how you sell</li><li class="li">Text, email and web chat in one inbox</li><li class="li">Custom fields for the details you track</li><li class="li">Proposals, contracts and payments on the record</li></ul></div>
</section>
<section id="auto" class="mod" aria-labelledby="m3">
<div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 16px"><span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">Automations · all plans</span><h2 id="m3" class="h2">The follow-ups happen without you.</h2><p class="lead">Start from templates for the moments that matter, then adjust the timing and wording.</p>
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px"><li class="li">Instant reply to new inquiries</li><li class="li">Appointment reminders and confirmations</li><li class="li">Proposal and payment chasers</li>@if ($mg->get('show_unverified_features'))<li class="li">Review requests after the event</li>@endif</ul></div>
<div aria-hidden="true" class="ui" style="flex: 1 1 460px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px">
<div class="card" style="padding: 16px"><b>New inquiry → reply</b><div style="color: #6B625E; margin-top: 4px">Text + email in 2 min</div><span class="chip" style="background: #E4F0EA; color: #1F5E4A; margin-top: 10px">On</span></div>
<div class="card" style="padding: 16px"><b>Booking reminder</b><div style="color: #6B625E; margin-top: 4px">24h and 2h before</div><span class="chip" style="background: #E4F0EA; color: #1F5E4A; margin-top: 10px">On</span></div>
<div class="card" style="padding: 16px"><b>Signed → payment</b><div style="color: #6B625E; margin-top: 4px">Request deposit</div><span class="chip" style="background: #E4F0EA; color: #1F5E4A; margin-top: 10px">On</span></div>
@if ($mg->get('show_unverified_features'))<div class="card" style="padding: 16px"><b>Review request</b><div style="color: #6B625E; margin-top: 4px">Day after the event</div><span class="chip" style="background: #EFEBE7; color: #4A4340; margin-top: 10px">Off</span></div>@endif
<div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
</section>
<section id="book" class="mod" aria-labelledby="m4" style="flex-wrap: wrap-reverse">
<div aria-hidden="true" class="card ui" style="flex: 1 1 460px; padding: 20px; display: flex; flex-direction: column; gap: 10px"><b style="font-size: 14px">Pick a time · Sat, June 14</b><div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px"><span style="padding: 10px; text-align: center; border-radius: 10px; border: 1px solid #E7E1DB">11:00</span><span style="padding: 10px; text-align: center; border-radius: 10px; border: 1px solid #E7E1DB">13:00</span><span style="padding: 10px; text-align: center; border-radius: 10px; background: #B4514A; color: #FFFFFF; font-weight: 600">17:00</span><span style="padding: 10px; text-align: center; border-radius: 10px; border: 1px solid #E7E1DB; color: #A99E96">19:00</span></div><span style="color: #6B625E">Confirmation and reminder sent automatically</span><div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
<div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 16px"><span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">Bookings &amp; calendar · all plans</span><h2 id="m4" class="h2">Booking that fits around your day.</h2><p class="lead">Share booking links, set availability per service and location, and keep everyone reminded.</p>
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px"><li class="li">Booking links and calendar sync</li><li class="li">Services with durations and buffers</li><li class="li">Reschedule and cancel flows for customers</li></ul></div>
</section>
<section id="seo" class="mod" aria-labelledby="m5">
<div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 16px"><span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">SEO &amp; ads · Growth plan</span><h2 id="m5" class="h2">See what brings customers in.</h2><p class="lead">Track search visibility, get clear recommendations, and see Google and Meta ad results next to the leads they created. If nothing has changed yet, it says so.</p>
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px"><li class="li">Keyword tracking and visibility reports</li><li class="li">Plain-language SEO recommendations</li><li class="li">Google Ads and Meta Ads performance in one view</li><li class="li">Cost per lead and per booking, from your own data</li></ul><p style="margin: 0; font-size: 13.5px; color: #5E5652">Ad spend is paid to Google or Meta directly.</p></div>
<div aria-hidden="true" class="card ui" style="flex: 1 1 460px; padding: 20px; display: flex; flex-direction: column; gap: 8px"><div style="display: flex; justify-content: space-between"><b style="font-size: 14px">Tracked keywords</b><span class="chip" style="background: #181413; color: #FFFFFF">Sample data</span></div><div style="display: flex; justify-content: space-between; padding: 10px 12px; background: #F7F5F2; border-radius: 10px"><b>photo booth rental near me</b><span style="color: #1F5E4A; font-weight: 600">#6 ▲4</span></div><div style="display: flex; justify-content: space-between; padding: 10px 12px; background: #F7F5F2; border-radius: 10px"><b>wedding photo booth</b><span style="color: #6B625E">#14 —</span></div><div style="display: flex; justify-content: space-between; padding: 10px 12px; background: #F7F5F2; border-radius: 10px"><b>event booth hire</b><span style="color: #1F5E4A; font-weight: 600">#9 ▲2</span></div><span style="color: #6B625E">Last updated Monday · next check in 4 days</span></div>
</section>
<section id="ai" class="mod" aria-labelledby="m6" style="flex-wrap: wrap-reverse">
<div aria-hidden="true" class="ui" style="flex: 1 1 460px; background: #181413; color: #FFFFFF; border-radius: 20px; padding: 22px; display: flex; flex-direction: column; gap: 12px"><div style="align-self: flex-end; padding: 10px 14px; border-radius: 14px; background: #2E2826">What should I focus on this week?</div><div style="display: flex; gap: 10px"><img src="{{ $mg->asset('img/mg-mark.png') }}" alt="" style="width: 26px; height: 26px"><div style="line-height: 1.55; color: #E9E2DC">Three leads from last week haven’t had a reply. Two asked about June dates you still have open. <b style="color: #FFFFFF">Start with those</b> — I’ve drafted a message for each.</div></div><div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
<div style="flex: 1 1 420px; display: flex; flex-direction: column; gap: 16px"><span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">AI assistant · Growth plan</span><h2 id="m6" class="h2">Advice based on your own business.</h2><p class="lead">Ask MotionGrove anything about your leads, bookings or marketing. It explains what’s happening and suggests a next step you can act on.</p><p style="margin: 0; font-size: 13.5px; color: #5E5652">Some AI features are usage-based.</p></div>
</section>
<section id="more" style="padding: 72px 0 112px; border-top: 1px solid #E7E1DB; display: flex; flex-direction: column; gap: 32px" aria-labelledby="m7">
<h2 id="m7" class="h2">Also included</h2>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px">
<div class="card" style="padding: 24px; display: flex; flex-direction: column; gap: 8px"><b style="font-size: 17px">Forms &amp; surveys</b><span style="color: #5E5652; line-height: 1.55">Lead capture and intake forms that feed your CRM.</span></div>
<div class="card" style="padding: 24px; display: flex; flex-direction: column; gap: 8px"><b style="font-size: 17px">Payments &amp; contracts</b><span style="color: #5E5652; line-height: 1.55">Proposals to sign and online payments through your own Stripe account.</span></div>
<div class="card" style="padding: 24px; display: flex; flex-direction: column; gap: 8px"><b style="font-size: 17px">Reporting</b><span style="color: #5E5652; line-height: 1.55">A home dashboard that shows what needs you and what’s working.</span></div>
<div class="card" style="padding: 24px; display: flex; flex-direction: column; gap: 8px"><b style="font-size: 17px">Multiple locations</b><span style="color: #5E5652; line-height: 1.55">Separate addresses, services and availability under one business.</span></div>
<div class="card" style="padding: 24px; display: flex; flex-direction: column; gap: 8px"><b style="font-size: 17px">Team access</b><span style="color: #5E5652; line-height: 1.55">Invite staff with the right permissions.</span></div>
<div class="card" style="padding: 24px; display: flex; flex-direction: column; gap: 8px; background: #181413; color: #FFFFFF; border-color: #181413"><b style="font-size: 17px">Agency &amp; white-label</b><span style="color: #C9C0BA; line-height: 1.55">Manage client businesses under your own brand on the Agency plan.</span></div>
</div>
<p style="margin: 0; font-size: 13px; color: #6B625E">Feature availability depends on your plan. <a href="{{ $mg->url('pricing') }}" style="text-decoration: underline">Compare plans</a>.</p>
</section>
</div>

@include('marketing.partials.cta-panel', ['title' => $c['cta']['title'], 'lead' => $c['cta']['lead'], 'buttons' => array_filter([
    ['label' => 'Get started', 'url' => $mg->getStartedUrl(), 'style' => 'btn-p'],
    $mg->hasBookDemo() ? ['label' => $c['cta']['secondary'], 'url' => $mg->bookDemoUrl(), 'style' => 'ghost'] : null,
])])
@endsection

@section('footer')
@include('marketing.partials.footer-compact')
@endsection
