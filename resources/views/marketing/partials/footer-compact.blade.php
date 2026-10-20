@php
    $legal = array_filter([
        'Privacy' => $mg->link('privacy'),
        'Terms' => $mg->link('terms'),
        'Acceptable Use' => $mg->link('acceptable_use'),
    ]);
@endphp
<footer style="background: #181413; color: #C9C0BA; padding: 48px 0 36px"><div class="wrap" style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 20px; font-size: 14px"><img src="{{ $mg->wordmark(true) }}" alt="{{ $mg->brandName() }}" style="height: 24px; width: auto"><nav aria-label="Footer" style="display: flex; flex-wrap: wrap; gap: 22px"><a href="{{ $mg->url('product') }}">Product</a><a href="{{ $mg->url('solutions') }}">Solutions</a><a href="{{ $mg->url('pricing') }}">Pricing</a><a href="{{ $mg->url('customers') }}">Customers</a>@if ($mg->hasBookDemo())<a href="{{ $mg->bookDemoUrl() }}">Contact</a>@endif @foreach ($legal as $label => $url)<a href="{{ $url }}">{{ $label }}</a>@endforeach</nav><span>© {{ date('Y') }} {{ $mg->brandName() }}</span></div></footer>
