@php
    $nav = [
        'product' => [$mg->navLabel('product', 'Product'), $mg->url('product')],
        'solutions' => [$mg->navLabel('solutions', 'Solutions'), $mg->url('solutions')],
        'how-it-works' => [$mg->navLabel('how-it-works', 'How it works'), $mg->url('how-it-works')],
        'customers' => [$mg->navLabel('customers', 'Customers'), $mg->url('customers')],
        'pricing' => [$mg->navLabel('pricing', 'Pricing'), $mg->url('pricing')],
        'resources' => [$mg->navLabel('resources', 'Resources'), $mg->url('home').'#faq'],
    ];
    $nav = array_filter($nav, fn ($key) => $mg->pageVisible($key), ARRAY_FILTER_USE_KEY);
    $showGetStarted = $active !== 'pricing';
@endphp
<header class="mg-header">
<div class="wrap mg-bar" style="display: flex; align-items: center; gap: 24px">
<a href="{{ $mg->url('home') }}" aria-label="{{ $mg->brandName() }} home" style="display: flex; align-items: center"><img src="{{ $mg->wordmark() }}" alt="{{ $mg->brandName() }}" width="169" height="30" class="mg-logo" style="height: 30px; width: auto; display: block"></a>
<nav class="navl" aria-label="Main" style="display: flex; align-items: center; gap: 2px; margin-left: 12px">
@foreach ($nav as $key => [$label, $href])
<a href="{{ $href }}"@if ($key === $active) aria-current="page" style="color: #8F3B35; font-weight: 600"@endif>{{ $label }}</a>
@endforeach
</nav>
<div style="flex: 1"></div>
<div style="display: flex; align-items: center; gap: 10px">
<a href="{{ $mg->loginUrl() }}" class="hide-m" style="font-size: 15px; font-weight: 600; padding: 10px 12px">Log in</a>
@if ($mg->hasBookDemo() && $active !== 'book-demo')
<a href="{{ $mg->bookDemoUrl() }}" class="btn btn-s hide-m">Book a demo</a>
@endif
@if ($showGetStarted)
<a href="{{ $mg->getStartedUrl() }}" class="btn btn-p mg-gs">Get started</a>
@endif
<details class="mg-menu show-m" style="position: relative">
<summary aria-label="Open menu" style="width: 44px; height: 44px; border-radius: 10px; border: 1px solid #DCD5CE; background: #FFFFFF; display: flex; align-items: center; justify-content: center"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg></summary>
<div style="position: absolute; right: -8px; top: 54px; width: min(360px, calc(100vw - 24px)); background: #FFFFFF; border: 1px solid #E7E1DB; border-radius: 18px; box-shadow: 0 20px 50px rgba(24,20,19,0.18); padding: 10px; display: flex; flex-direction: column">
@foreach ($nav as $key => [$label, $href])
<a href="{{ $href }}" style="padding: 14px; font-size: 18px; font-weight: 600">{{ $label }}</a>
@endforeach
<div style="display: flex; flex-direction: column; gap: 8px; padding: 10px 4px 4px; border-top: 1px solid #EFEAE5; margin-top: 6px">
@if ($mg->hasBookDemo())
<a href="{{ $mg->bookDemoUrl() }}" class="btn btn-s">Book a demo</a>
@endif
<a href="{{ $mg->loginUrl() }}" class="btn" style="color: #2E2826">Log in</a>
</div>
</div>
</details>
</div>
</div>
</header>
