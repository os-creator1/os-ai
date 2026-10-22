@php
    $c = $mg->get('copy.home.faq');
    $faqs = $mg->faqs('home');
    $short = array_values(array_filter($faqs, fn ($f) => ! empty($f['mobile'])));
@endphp
@if ($faqs)
<section id="faq" class="sec faq-sec" aria-labelledby="faq-h" style="padding-top: 0">
<div class="wrap" style="display: flex; flex-wrap: wrap; gap: 56px">
<div style="flex: 1 1 300px; display: flex; flex-direction: column; gap: 18px"><span class="eyebrow hide-m">{{ $c['eyebrow'] }}</span><h2 id="faq-h" class="h2"><span class="hide-m">{{ $c['title'] }}</span><span class="show-m">{{ $c['title_mobile'] }}</span></h2>@if ($mg->hasBookDemo())<p class="lead hide-m">{{ $c['lead'] }} <a href="{{ $mg->bookDemoUrl() }}" style="color: #8F3B35; font-weight: 600; text-decoration: underline">{{ $c['lead_link'] }}</a>.</p>@endif</div>
<div class="hide-m" style="flex: 2 1 560px; display: flex; flex-direction: column; border-top: 1px solid #E2DBD4">
@foreach ($faqs as $f)
@include('marketing.partials.faq-item', ['q' => $f['q'], 'a' => $f['a'], 'link' => ($f['link'] ?? null) ? $mg->url($f['link']) : null, 'size' => 'lg'])
@endforeach
</div>
{{-- Phone: the short list from Home-phone.dc.html, with the full list behind "See all questions". --}}
<div class="show-m" style="width: 100%">
<div style="border-top: 1px solid #E2DBD4">
@foreach ($short as $f)
@include('marketing.partials.faq-item', ['q' => $f['mobile'][0], 'a' => $f['mobile'][1], 'size' => 'md'])
@endforeach
</div>
<details class="faq-all" style="margin-top: 18px"><summary style="font-weight: 600; color: #8F3B35">See all questions</summary>
<div style="border-top: 1px solid #E2DBD4; margin-top: 14px">
@foreach ($faqs as $f)
@include('marketing.partials.faq-item', ['q' => $f['q'], 'a' => $f['a'], 'link' => ($f['link'] ?? null) ? $mg->url($f['link']) : null, 'size' => 'md'])
@endforeach
</div>
</details>
</div>
</div>
</section>
@endif
