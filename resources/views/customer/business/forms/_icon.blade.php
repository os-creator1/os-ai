{{-- Inline 24px stroke icons, drawn exactly as in the Forms design references.
     Usage: @include('customer.business.forms._icon', ['n' => 'eye', 'style' => '...']) --}}
@php
    $paths = [
        'back' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'eye' => '<path d="M2.5 12s3.5-6.5 9.5-6.5 9.5 6.5 9.5 6.5-3.5 6.5-9.5 6.5S2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/>',
        'code' => '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4"/>',
        'form' => '<rect x="5" y="3.5" width="14" height="17" rx="2"/><path d="M9 8.5h6M9 12.5h6M9 16.5h3"/>',
        'bell' => '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15zM10 20.5h4"/>',
        'chat' => '<path d="M4 5h16v11H9l-5 4z"/>',
        'mail' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'chevron' => '<path d="M10 7l5 5-5 5"/>',
        'down' => '<path d="M7 10l5 5 5-5"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'export' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        'inbox' => '<path d="M4 13h4l2 3h4l2-3h4M5 5h14l1 8v6H4v-6z"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'info' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8h.01"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'dots' => '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
    ];
@endphp
<svg class="ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"@isset($style) style="{{ $style }}"@endisset>{!! $paths[$n] ?? '' !!}</svg>
