{{--
    Meta Ads Module V1 — the creative preview cell of an ad: the thumbnail
    (only when the reader already validated it: https + allow-listed host),
    the ad name and the creative's title / body. All text is raw customer
    data and ESCAPED. The image never sends a referrer and loads lazily.
--}}
@php
    $thumb = $creative['thumbnail_url'] ?? null;
@endphp
<div class="d-flex gap-1 align-items-start" data-role="creative-preview">
    @if($thumb)
        <img src="{{ $thumb }}" alt="{{ $name !== '' ? 'Preview of ' . $name : 'Ad preview' }}" width="56" height="56"
             referrerpolicy="no-referrer" loading="lazy" class="rounded flex-shrink-0" style="object-fit: cover;" data-role="creative-thumbnail"
             onerror="this.remove()">
    @endif
    <div class="min-w-0">
        <div class="fw-semibold" data-role="ad-name">{{ $name }}</div>
        @if(! empty($creative['title']))
            <div class="text-caption" data-role="creative-title">{{ \Illuminate\Support\Str::limit((string) $creative['title'], 90) }}</div>
        @endif
        @if(! empty($creative['body']))
            <div class="text-caption text-muted" data-role="creative-body">{{ \Illuminate\Support\Str::limit((string) $creative['body'], 140) }}</div>
        @endif
    </div>
</div>
