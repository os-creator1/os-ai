@php($c = $mg->get('copy.home.capabilities'))
<section class="sec" aria-labelledby="cap-h">
<div class="wrap cap-wrap" style="display: flex; flex-direction: column; gap: 120px">
<div style="display: flex; flex-direction: column; gap: 18px; max-width: 760px">
<span class="eyebrow">{{ $c['eyebrow'] }}</span>
<h2 id="cap-h" class="h2"><span class="hide-m">{{ $c['title'] }}</span><span class="show-m">{{ $c['title_mobile'] }}</span></h2>
</div>
{{-- Phone: swipe row of the six capabilities (Home-phone.dc.html). --}}
<div class="scroller show-m">
@foreach ($mg->get('capability_cards') as [$tag, $title, $text])
<article class="card" style="width: 290px; padding: 22px; display: flex; flex-direction: column; gap: 10px; border-radius: 18px"><span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">{{ $tag }}</span><b style="font-size: 20px; letter-spacing: -0.01em">{{ $title }}</b><span style="color: #5E5652; font-size: 15px; line-height: 1.55">{{ $text }}</span></article>
@endforeach
</div>
<div class="hide-m" style="display: flex; flex-direction: column; gap: 120px">
<div style="display: flex; align-items: center; gap: 16px; margin-bottom: -64px"><span style="height: 32px; padding: 0 14px; border-radius: 999px; background: #181413; color: #FFFFFF; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center">1 · Get found and get leads</span><span style="flex: 1; height: 1px; background: #E2DBD4"></span></div>

<!-- SEO -->
<div style="display: flex; flex-wrap: wrap; gap: 64px; align-items: center">
<div style="flex: 1 1 400px; display: flex; flex-direction: column; gap: 18px">
<span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">SEO, ads — or both</span>
<h3 style="margin: 0; font-size: clamp(28px, 3vw, 38px); line-height: 1.1; font-weight: 800; letter-spacing: -0.03em">Show up when customers search for what you do.</h3>
<p class="lead">{{ $mg->brandName() }} tracks where you appear on Google, tells you in plain language what to improve next, and helps you get it done — so your business is working to bring in leads, not just to manage them.</p>
<p class="lead">Ads are optional. Grow through search alone, add ads when you want leads faster, or run both — we’ll help you pick what fits your situation.</p>
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; font-size: 16px">
<li style="display: flex; gap: 12px; align-items: center"><span class="tick"><svg class="ic" viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke-width: 2.6"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>Keyword and local visibility tracking</li>
<li style="display: flex; gap: 12px; align-items: center"><span class="tick"><svg class="ic" viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke-width: 2.6"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>Clear, prioritized recommendations</li>
<li style="display: flex; gap: 12px; align-items: center"><span class="tick"><svg class="ic" viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke-width: 2.6"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>If you run ads: Google and Meta results next to the leads they bring</li>
<li style="display: flex; gap: 12px; align-items: center"><span class="tick"><svg class="ic" viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke-width: 2.6"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>Honest reporting — if nothing moved, it says so</li>
</ul>
</div>
<div aria-hidden="true" class="ui" style="flex: 1 1 480px; display: flex; flex-direction: column; gap: 14px">
<div class="card" style="padding: 20px; display: flex; flex-direction: column; gap: 8px; box-shadow: 0 20px 40px -24px rgba(24,20,19,.3)">
<div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 4px"><b style="font-size: 14px">Search visibility</b><span class="chip" style="background: #181413; color: #FFFFFF">Sample data</span></div>
<div style="display: flex; justify-content: space-between; padding: 10px 12px; background: #F7F5F2; border-radius: 10px"><b>photo booth rental near me</b><span style="color: #1F5E4A; font-weight: 600">#6 ▲4</span></div>
<div style="display: flex; justify-content: space-between; padding: 10px 12px; background: #F7F5F2; border-radius: 10px"><b>wedding photo booth</b><span style="color: #6B625E">#14 —</span></div>
<div style="display: flex; justify-content: space-between; padding: 10px 12px; background: #F7F5F2; border-radius: 10px"><b>360 booth hire</b><span style="color: #1F5E4A; font-weight: 600">#9 ▲2</span></div>
<span style="color: #6B625E; padding-top: 4px">Next step: add a service page for corporate events</span>
</div>
<div class="card" style="padding: 20px; display: flex; flex-direction: column; gap: 10px; margin-left: 48px">
<b style="font-size: 14px">Where this month’s leads came from</b>
<div style="display: flex; align-items: center; gap: 10px"><span style="width: 90px; color: #6B625E">Google search</span><span style="flex: 1; height: 10px; border-radius: 5px; background: #EFEAE5"><span style="display: block; width: 62%; height: 10px; border-radius: 5px; background: #B4514A"></span></span></div>
<div style="display: flex; align-items: center; gap: 10px"><span style="width: 90px; color: #6B625E">Meta ads</span><span style="flex: 1; height: 10px; border-radius: 5px; background: #EFEAE5"><span style="display: block; width: 38%; height: 10px; border-radius: 5px; background: #1F5E4A"></span></span></div>
<div style="display: flex; align-items: center; gap: 10px"><span style="width: 90px; color: #6B625E">Referrals</span><span style="flex: 1; height: 10px; border-radius: 5px; background: #EFEAE5"><span style="display: block; width: 18%; height: 10px; border-radius: 5px; background: #A99E96"></span></span></div>
</div>
</div>
</div>

<!-- Website -->
<div style="display: flex; flex-wrap: wrap-reverse; gap: 64px; align-items: center">
<div style="flex: 1 1 400px; display: flex; flex-direction: column; gap: 18px; order: 2">
<span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">Website</span>
<h3 style="margin: 0; font-size: clamp(28px, 3vw, 38px); line-height: 1.1; font-weight: 800; letter-spacing: -0.03em">A website built to turn visitors into inquiries.</h3>
<p class="lead">Answer a few questions about what you offer and where you work. {{ $mg->brandName() }} builds a mobile-ready site with clear services, photos and a booking form on every page — designed to get people to reach out, and easy to edit afterwards.</p>
<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; font-size: 16px">
<li style="display: flex; gap: 12px; align-items: center"><span class="tick"><svg class="ic" viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke-width: 2.6"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>Guided questions instead of a blank page</li>
<li style="display: flex; gap: 12px; align-items: center"><span class="tick"><svg class="ic" viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke-width: 2.6"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>Hosting and your own domain</li>
<li style="display: flex; gap: 12px; align-items: center"><span class="tick"><svg class="ic" viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke-width: 2.6"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>Every form feeds straight into your CRM</li>
</ul>
</div>
<div aria-hidden="true" class="ui" style="flex: 1 1 480px; position: relative; min-height: 420px">
<div class="card" style="position: absolute; left: 0; top: 0; width: 62%; padding: 20px; display: flex; flex-direction: column; gap: 12px; box-shadow: 0 20px 40px -24px rgba(24,20,19,.3)">
<span style="color: #6B625E">Question 3 of 8</span>
<b style="font-size: 16px">What services do you offer?</b>
<div style="display: flex; flex-wrap: wrap; gap: 6px"><span class="chip" style="background: #181413; color: #FFFFFF">Weddings</span><span class="chip" style="background: #181413; color: #FFFFFF">Corporate events</span><span class="chip" style="background: #EFEBE7">Birthdays</span><span class="chip" style="background: #EFEBE7">+ Add</span></div>
<div style="height: 6px; border-radius: 3px; background: #EFEAE5"><div style="width: 38%; height: 6px; border-radius: 3px; background: #B4514A"></div></div>
</div>
<div class="card" style="position: absolute; right: 0; top: 70px; width: 64%; overflow: hidden; box-shadow: 0 30px 60px -24px rgba(24,20,19,.35)">
<div style="height: 30px; background: #F3EFEB; border-bottom: 1px solid #E7E1DB; display: flex; align-items: center; padding: 0 12px; color: #6B625E">lumenevents.com</div>
<div style="padding: 18px; display: flex; flex-direction: column; gap: 10px">
<div style="height: 120px; border-radius: 10px; background: #D9C7BF; display: flex; align-items: flex-end; padding: 12px"><b style="font-size: 18px; color: #FFFFFF">Unforgettable events, captured.</b></div>
<div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px"><div style="height: 54px; border-radius: 8px; background: #F3EFEB"></div><div style="height: 54px; border-radius: 8px; background: #F3EFEB"></div><div style="height: 54px; border-radius: 8px; background: #F3EFEB"></div></div>
<span style="align-self: flex-start; padding: 8px 14px; border-radius: 8px; background: #B4514A; color: #FFFFFF; font-weight: 600">Check availability</span>
</div>
</div>
<div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
</div>

<div style="display: flex; align-items: center; gap: 16px; margin-bottom: -64px"><span style="height: 32px; padding: 0 14px; border-radius: 999px; background: #181413; color: #FFFFFF; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center">2 · Turn leads into bookings</span><span style="flex: 1; height: 1px; background: #E2DBD4"></span></div>

<!-- CRM -->
<div style="display: flex; flex-wrap: wrap; gap: 64px; align-items: center">
<div aria-hidden="true" class="ui" style="flex: 1 1 480px; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px">
<div class="card" style="padding: 18px; display: flex; flex-direction: column; gap: 12px">
<div style="display: flex; align-items: center; gap: 10px"><span style="width: 40px; height: 40px; border-radius: 50%; background: #F6E7E4; color: #8F3B35; display: flex; align-items: center; justify-content: center; font-weight: 700">JP</span><div><b style="font-size: 14px">Jordan Park</b><div style="color: #6B625E">Wedding · June 14</div></div></div>
<div style="display: flex; gap: 6px; flex-wrap: wrap"><span class="chip" style="background: #FBEFD9; color: #7A4A0A">Proposal sent</span><span class="chip" style="background: #EFEBE7">Website form</span></div>
<div style="border-top: 1px solid #F0EBE6; padding-top: 10px; display: flex; flex-direction: column; gap: 8px; color: #4A4340"><span>Proposal opened · 2h ago</span><span>Follow-up text sent · yesterday</span><span>Inquiry received · Mon</span></div>
</div>
<div class="card" style="padding: 18px; display: flex; flex-direction: column; gap: 10px">
<b style="font-size: 13.5px">Conversation</b>
<div style="align-self: flex-start; max-width: 85%; padding: 9px 12px; border-radius: 12px 12px 12px 4px; background: #F3EFEB">Hi! Do you have a booth free on June 14?</div>
<div style="align-self: flex-end; max-width: 85%; padding: 9px 12px; border-radius: 12px 12px 4px 12px; background: #B4514A; color: #FFFFFF">We do! Here’s our package list and a link to book.</div>
<div style="align-self: flex-start; max-width: 85%; padding: 9px 12px; border-radius: 12px 12px 12px 4px; background: #F3EFEB">Perfect, booking now.</div>
<span style="display: flex; gap: 6px; color: #6B625E">Text · Email · Web chat in one thread</span>
</div>
<div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
<div style="flex: 1 1 400px; display: flex; flex-direction: column; gap: 18px; order: -1">
<span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">CRM &amp; conversations</span>
<h3 style="margin: 0; font-size: clamp(28px, 3vw, 38px); line-height: 1.1; font-weight: 800; letter-spacing: -0.03em">Every new lead caught, organized and answered.</h3>
<p class="lead">Every inquiry from your website, ads and messages lands in one place. See where each lead stands, what was said last and what happens next — so the customers you worked to attract don’t slip away.</p>
<a href="{{ $mg->url('product') }}" style="font-weight: 600; color: #8F3B35; display: inline-flex; gap: 6px; align-items: center">Explore the CRM<svg class="ic" viewBox="0 0 24 24" aria-hidden="true" style="width: 16px; height: 16px"><path d="M5 12h14M13 6l6 6-6 6"></path></svg></a>
</div>
</div>

<!-- Automations -->
<div style="display: flex; flex-wrap: wrap; gap: 64px; align-items: center">
<div style="flex: 1 1 400px; display: flex; flex-direction: column; gap: 18px; order: 2">
<span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">Automations &amp; follow-ups</span>
<h3 style="margin: 0; font-size: clamp(28px, 3vw, 38px); line-height: 1.1; font-weight: 800; letter-spacing: -0.03em">Reply in minutes, even when you’re on a job.</h3>
<p class="lead">Ready-made workflows answer new inquiries, send reminders, chase unsigned proposals and request payment after signing. Start from a template; adjust the wording to sound like you.</p>
</div>
<div aria-hidden="true" class="ui" style="flex: 1 1 480px; display: flex; flex-direction: column; align-items: center; gap: 0">
<div class="card" style="width: min(360px, 100%); padding: 14px 16px; display: flex; gap: 12px; align-items: center"><span style="width: 32px; height: 32px; border-radius: 9px; background: #F6E7E4; color: #8F3B35; display: flex; align-items: center; justify-content: center"><svg class="ic" viewBox="0 0 24 24" style="width: 15px; height: 15px"><path d="M13 3L5 14h6l-1 7 8-11h-6z"></path></svg></span><div><span style="color: #6B625E">When</span><div><b style="font-size: 13.5px">A new inquiry comes in</b></div></div></div>
<span style="width: 2px; height: 22px; background: #DCD5CE"></span>
<div class="card" style="width: min(360px, 100%); padding: 14px 16px; display: flex; gap: 12px; align-items: center"><span style="width: 32px; height: 32px; border-radius: 9px; background: #E4F0EA; color: #1F5E4A; display: flex; align-items: center; justify-content: center"><svg class="ic" viewBox="0 0 24 24" style="width: 15px; height: 15px"><path d="M4 5h16v11H9l-5 4z"></path></svg></span><div><span style="color: #6B625E">Send text</span><div><b style="font-size: 13.5px">“Thanks! Here’s our availability…”</b></div></div></div>
<span style="width: 2px; height: 22px; background: #DCD5CE"></span>
<div class="card" style="width: min(360px, 100%); padding: 14px 16px; display: flex; gap: 12px; align-items: center"><span style="width: 32px; height: 32px; border-radius: 9px; background: #EFEBE7; color: #4A4340; display: flex; align-items: center; justify-content: center"><svg class="ic" viewBox="0 0 24 24" style="width: 15px; height: 15px"><circle cx="12" cy="12" r="8"></circle><path d="M12 8v4l3 2"></path></svg></span><div><span style="color: #6B625E">Wait</span><div><b style="font-size: 13.5px">1 day — stop if they reply</b></div></div></div>
<span style="width: 2px; height: 22px; background: #DCD5CE"></span>
<div class="card" style="width: min(360px, 100%); padding: 14px 16px; display: flex; gap: 12px; align-items: center"><span style="width: 32px; height: 32px; border-radius: 9px; background: #E4F0EA; color: #1F5E4A; display: flex; align-items: center; justify-content: center"><svg class="ic" viewBox="0 0 24 24" style="width: 15px; height: 15px"><path d="M4 6h16v12H4zM4 7l8 6 8-6"></path></svg></span><div><span style="color: #6B625E">Send email</span><div><b style="font-size: 13.5px">Packages and a link to book</b></div></div></div>
<div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
</div>

<div style="display: flex; align-items: center; gap: 16px; margin-bottom: -64px"><span style="height: 32px; padding: 0 14px; border-radius: 999px; background: #181413; color: #FFFFFF; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center">3 · Run it with less busywork</span><span style="flex: 1; height: 1px; background: #E2DBD4"></span></div>

<!-- Bento: bookings / payments / AI -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px">
<article class="card" style="padding: 32px; display: flex; flex-direction: column; gap: 14px">
<span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">Bookings &amp; calendars</span>
<h3 style="margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -0.025em">Let customers book the time that suits you.</h3>
<p style="margin: 0; color: #5E5652; line-height: 1.6">Booking links, availability and reminders that keep your calendar full and your no-shows down.</p>
<div aria-hidden="true" class="ui" style="margin-top: auto; display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 6px; padding-top: 12px">
<span style="color: #6B625E; text-align: center">Mon</span><span style="color: #6B625E; text-align: center">Tue</span><span style="color: #6B625E; text-align: center">Wed</span><span style="color: #6B625E; text-align: center">Thu</span><span style="color: #6B625E; text-align: center">Fri</span>
<span style="height: 44px; border-radius: 8px; background: #F6E7E4"></span><span style="height: 44px; border-radius: 8px; background: #F3EFEB"></span><span style="height: 44px; border-radius: 8px; background: #B4514A"></span><span style="height: 44px; border-radius: 8px; background: #F3EFEB"></span><span style="height: 44px; border-radius: 8px; background: #F6E7E4"></span>
<span style="height: 44px; border-radius: 8px; background: #F3EFEB"></span><span style="height: 44px; border-radius: 8px; background: #E4F0EA"></span><span style="height: 44px; border-radius: 8px; background: #F3EFEB"></span><span style="height: 44px; border-radius: 8px; background: #E4F0EA"></span><span style="height: 44px; border-radius: 8px; background: #F3EFEB"></span>
<div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
</article>
<article class="card" style="padding: 32px; display: flex; flex-direction: column; gap: 14px">
<span class="chip" style="background: #F6E7E4; color: #8F3B35; align-self: flex-start">Proposals &amp; payments</span>
<h3 style="margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -0.025em">Signed and paid without the back-and-forth.</h3>
<p style="margin: 0; color: #5E5652; line-height: 1.6">Send a proposal, let the customer sign online, and request payment automatically through your own Stripe account.</p>
<div aria-hidden="true" class="ui" style="margin-top: auto; display: flex; flex-direction: column; gap: 6px; padding-top: 12px">
<div style="display: flex; justify-content: space-between; padding: 9px 12px; background: #F7F5F2; border-radius: 10px"><b>Proposal sent</b><span style="color: #6B625E">Mon</span></div>
<div style="display: flex; justify-content: space-between; padding: 9px 12px; background: #F7F5F2; border-radius: 10px"><b>Signed by customer</b><span style="color: #1F5E4A; font-weight: 600">Tue</span></div>
<div style="display: flex; justify-content: space-between; padding: 9px 12px; background: #E4F0EA; border-radius: 10px"><b>Deposit paid</b><span style="color: #1F5E4A; font-weight: 600">Tue</span></div>
<div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
</article>
<article style="padding: 32px; display: flex; flex-direction: column; gap: 14px; border-radius: 20px; background: #EDE5DF; grid-column: 1 / -1">
<div style="display: flex; flex-wrap: wrap; gap: 40px; align-items: center">
<div style="flex: 1 1 360px; display: flex; flex-direction: column; gap: 14px">
<span class="chip" style="background: #FFFFFF; color: #8F3B35; align-self: flex-start">AI growth assistance</span>
<h3 style="margin: 0; font-size: 30px; font-weight: 800; letter-spacing: -0.025em">Ask what to do next. Get an answer about your business.</h3>
<p style="margin: 0; color: #4A4340; line-height: 1.6; font-size: 17px">Ask {{ $mg->brandName() }} looks at your leads, bookings and marketing and suggests practical next steps — in plain language, with links to do them.</p>
</div>
<div aria-hidden="true" class="ui" style="flex: 1 1 420px; background: #FFFFFF; border-radius: 18px; padding: 18px; display: flex; flex-direction: column; gap: 12px; box-shadow: 0 20px 40px -24px rgba(24,20,19,.3)">
<div style="align-self: flex-end; padding: 10px 14px; border-radius: 14px; background: #F3EFEB; font-size: 13.5px">Why did I get fewer inquiries this month?</div>
<div style="display: flex; gap: 10px"><img src="{{ $mg->mark() }}" alt="" style="width: 26px; height: 26px; flex: none"><div style="font-size: 13.5px; line-height: 1.55; color: #2E2826">Website visits held steady, but your contact form moved below the fold after the last edit. <b>Move it back to the top of your Weddings page</b> — I can make the change if you’d like.</div></div>
<div style="display: flex; gap: 8px; padding-left: 36px"><span class="chip" style="background: #B4514A; color: #FFFFFF">Make the change</span><span class="chip" style="background: #EFEBE7">Show me</span></div>
<div class="ex-note">{{ $mg->get('copy.example_tag') }}</div></div>
</div>
</article>
</div>

</div>
</div>
</section>
