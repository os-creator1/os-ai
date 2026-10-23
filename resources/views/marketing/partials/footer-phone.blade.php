@php
    $social = $mg->social();
    $help = $mg->helpUrl();
    $legal = $mg->legalLinks(true);
@endphp
<footer style="background: #181413; color: #C9C0BA; padding: 48px 20px 32px; display: flex; flex-direction: column; gap: 28px; font-size: 15px">
<img src="{{ $mg->wordmark(true) }}" alt="{{ $mg->brandName() }}" style="height: 24px; width: auto; align-self: flex-start">
<nav aria-label="Footer" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 20px">@if ($mg->pageVisible('product'))<a href="{{ $mg->url('product') }}">Product</a>@endif @if ($mg->pageVisible('solutions'))<a href="{{ $mg->url('solutions') }}">Solutions</a>@endif @if ($mg->pageVisible('pricing'))<a href="{{ $mg->url('pricing') }}">Pricing</a>@endif @if ($mg->pageVisible('customers'))<a href="{{ $mg->url('customers') }}">Customers</a>@endif @if ($mg->hasBookDemo())<a href="{{ $mg->bookDemoUrl() }}">Contact</a>@endif<a href="{{ $mg->loginUrl() }}">Log in</a><a href="{{ $help ?? $mg->url('home').'#faq' }}">Help</a>@foreach ($social as $label => $url)<a href="{{ $url }}" rel="noopener" target="_blank">{{ $label }}</a>@endforeach</nav>
<div style="border-top: 1px solid #3A322F; padding-top: 18px; display: flex; flex-direction: column; gap: 8px; font-size: 13px">@if ($legal)<span style="display: flex; flex-wrap: wrap; gap: 6px 16px">@foreach ($legal as $label => $url)<a href="{{ $url }}">{{ $label }}</a>@endforeach</span>@endif<span>© {{ date('Y') }} {{ $mg->legalName() }}</span></div>
</footer>
