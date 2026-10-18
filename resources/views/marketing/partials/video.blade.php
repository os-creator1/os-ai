{{--
    One testimonial video slot. Click-to-load: a thumbnail and a real <button> until the visitor clicks,
    then an iframe from youtube-nocookie.com with autoplay. Without JavaScript the "Watch on YouTube" link works.
    $s = slot from MarketingSite::testimonials(); $size = 'lg' | 'md' | 'sm'
--}}
@php
    $btn = ['lg' => 'width: 80px; height: 80px', 'md' => '', 'sm' => 'width: 52px; height: 52px', 'mob' => 'width: 56px; height: 56px'][$size] ?? '';
    $icon = ['lg' => 28, 'md' => 22, 'sm' => 20, 'mob' => 20][$size] ?? 22;
    $radius = ['sm' => 'border-radius: 12px', 'mob' => 'border-radius: 14px'][$size] ?? '';
@endphp
<div class="vid" style="{{ $radius }}"@unless ($s['placeholder']) data-video="{{ $s['embedUrl'] }}" data-video-title="Customer story: {{ $s['customerName'] }}{{ $s['businessName'] ? ', '.$s['businessName'] : '' }}"@endunless>
@if ($s['placeholder'])
<span aria-hidden="true" style="position: absolute; left: 12px; bottom: 12px; font-size: 11.5px; font-weight: 600; color: #E9E2DC; background: rgba(0,0,0,.35); padding: 3px 8px; border-radius: 5px">Video placeholder · [{{ $s['label'] }}]</span>
<button type="button" class="play" disabled aria-label="Video not available yet: {{ $s['customerName'] }} story" style="{{ $btn }}"><svg viewBox="0 0 24 24" aria-hidden="true" style="width: {{ $icon }}px; height: {{ $icon }}px; margin-left: 3px; fill: currentColor"><path d="M7 4.5v15l13-7.5z"></path></svg></button>
@else
<img class="thumb" src="{{ $s['thumb'] }}" alt="" loading="lazy" width="480" height="360">
<button type="button" class="play" data-video-play aria-label="Play video: {{ $s['customerName'] }} story" style="{{ $btn }}"><svg viewBox="0 0 24 24" aria-hidden="true" style="width: {{ $icon }}px; height: {{ $icon }}px; margin-left: 3px; fill: currentColor"><path d="M7 4.5v15l13-7.5z"></path></svg></button>
<a class="vid-fallback" href="{{ $s['watchUrl'] }}" rel="noopener" target="_blank">Watch on YouTube</a>
@endif
</div>
