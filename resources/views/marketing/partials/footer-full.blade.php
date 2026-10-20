@php
    $social = $mg->social();
    $help = $mg->helpUrl();
    $legal = array_filter([
        'Privacy Policy' => $mg->link('privacy'),
        'Terms of Service' => $mg->link('terms'),
        'Acceptable Use Policy' => $mg->link('acceptable_use'),
    ]);
@endphp
<footer style="background: #181413; color: #C9C0BA; padding: 72px 0 40px">
<div class="wrap" style="display: flex; flex-direction: column; gap: 56px">
<div style="display: flex; flex-wrap: wrap; gap: 48px; justify-content: space-between">
<div style="flex: 1 1 260px; max-width: 320px; display: flex; flex-direction: column; gap: 16px"><img src="{{ $mg->wordmark(true) }}" alt="{{ $mg->brandName() }}" style="height: 28px; width: auto; align-self: flex-start"><p style="margin: 0; line-height: 1.6; font-size: 14.5px">{{ $mg->get('copy.footer_blurb') }}</p></div>
<nav aria-label="Footer" style="flex: 2 1 520px; display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 32px; font-size: 14.5px">
<div style="display: flex; flex-direction: column; gap: 12px"><b style="color: #FFFFFF; font-size: 13px">Product</b>@if ($mg->pageVisible('product'))<a href="{{ $mg->url('product') }}">Features</a>@endif@if ($mg->pageVisible('how-it-works'))<a href="{{ $mg->url('how-it-works') }}">How it works</a>@endif@if ($mg->pageVisible('pricing'))<a href="{{ $mg->url('pricing') }}">Pricing</a>@endif<a href="{{ $mg->loginUrl() }}">Log in</a></div>
<div style="display: flex; flex-direction: column; gap: 12px"><b style="color: #FFFFFF; font-size: 13px">Company</b>@if ($mg->pageVisible('solutions'))<a href="{{ $mg->url('solutions') }}">Solutions</a>@endif@if ($mg->pageVisible('customers'))<a href="{{ $mg->url('customers') }}">Customers</a>@endif@if ($mg->hasBookDemo())<a href="{{ $mg->bookDemoUrl() }}">Contact</a><a href="{{ $mg->bookDemoUrl() }}">Book a demo</a>@endif</div>
<div style="display: flex; flex-direction: column; gap: 12px"><b style="color: #FFFFFF; font-size: 13px">Support</b><a href="{{ $mg->url('home') }}#faq">Help &amp; FAQ</a>@if ($help)<a href="{{ $help }}">{{ $mg->link('help_centre') ? 'Help centre' : 'Email support' }}</a>@endif</div>
@if ($social)
<div style="display: flex; flex-direction: column; gap: 12px"><b style="color: #FFFFFF; font-size: 13px">Follow</b>@foreach ($social as $label => $url)<a href="{{ $url }}" rel="noopener" target="_blank">{{ $label }}</a>@endforeach</div>
@endif
</nav>
</div>
<div id="footer" style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 16px; padding-top: 28px; border-top: 1px solid #3A322F; font-size: 13.5px"><span>© {{ date('Y') }} {{ $mg->legalName() }}. All rights reserved.</span>@if ($legal)<span style="display: flex; flex-wrap: wrap; gap: 20px">@foreach ($legal as $label => $url)<a href="{{ $url }}">{{ $label }}</a>@endforeach</span>@endif</div>
</div>
</footer>
