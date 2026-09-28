@props([
    'title',
    'description' => null,
    'canonical' => null,
])

{{--
    Public Marketing Homepage contract. No reusable SEO/meta-tag component
    existed anywhere in the app outside the customer Website builder's own
    page.blade.php (which is specific to that renderer's $page/$websiteMeta
    shape) — this is a small, generic one for the platform's own public
    pages only.
--}}
<title>{{ $title }}</title>
@if ($description)
    <meta name="description" content="{{ $description }}">
@endif
<link rel="canonical" href="{{ $canonical ?? url()->current() }}">
<meta name="robots" content="index, follow">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $title }}">
@if ($description)
    <meta property="og:description" content="{{ $description }}">
@endif
<meta property="og:url" content="{{ $canonical ?? url()->current() }}">
