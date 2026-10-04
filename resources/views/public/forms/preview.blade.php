<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Preview — {{ $name }}</title>
    @include('public.forms._style', ['design' => $version->style()])
    <style>body{margin:0;background:var(--pf-bg)}.pf-preview-gap{height:1.25rem}</style>
</head>
<body class="pf-scope" data-role="form-preview">
<main class="pf-shell">
    {{-- The builder's Preview: the SAME partials the public page uses
         (_style, _card, _fields), fed the unsaved document. A questionnaire
         shows each step as its own card, in the order a visitor walks them. --}}
    @foreach ($pages as $index => $page)
        @include('public.forms._card', [
            'name' => $name,
            'preview' => true,
            'page' => $page,
            'pageNumber' => $index + 1,
            'pageCount' => count($pages),
            'isLast' => $index === count($pages) - 1,
            'fields' => $version->fieldsOnPage($page['key']),
            'answers' => [],
            'backUrl' => null,
        ])
        @if (! $loop->last)<div class="pf-preview-gap"></div>@endif
    @endforeach
</main>
</body>
</html>
