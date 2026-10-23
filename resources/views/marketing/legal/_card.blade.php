<a class="lg-doccard" href="{{ $registry->url($d->slug) }}">
<span class="eyebrow">{{ $d->category }}@if (! $d->isPublished()) · Draft @endif</span>
<b>{{ $d->title }}</b>
<span>{{ $d->description }}</span>
<small>Version {{ $d->version }} · Effective {{ $d->effectiveLabel() }}</small>
</a>
