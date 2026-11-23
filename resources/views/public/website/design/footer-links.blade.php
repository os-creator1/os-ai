{{--
    One footer column's links. A compact footer (Emerald Gold) lists the first six and folds the rest into a native "More"
    disclosure, so no page loses its link. Every other design lists them all. Escaped output only.
--}}
@php
    $limit = $design->variant('footer') === 'compact' ? 6 : null;
    $shown = $limit === null ? $links : array_slice($links, 0, $limit);
    $more = $limit === null ? [] : array_slice($links, $limit);
@endphp
@foreach ($shown as $link)
    <li><a href="{{ $link['url'] }}">{{ $link['title'] }}</a></li>
@endforeach
@if ($more !== [])
    <li class="wd-footer-more">
        <details>
            <summary>More</summary>
            <ul>
                @foreach ($more as $link)
                    <li><a href="{{ $link['url'] }}">{{ $link['title'] }}</a></li>
                @endforeach
            </ul>
        </details>
    </li>
@endif
