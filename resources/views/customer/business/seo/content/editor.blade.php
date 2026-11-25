{{--
    SEO → Content → article editor (design: "Content — edit AI-written draft"). One form; every action button posts the
    same fields to its own route, so "Publish" and "Schedule" save what is on screen first. The body is safe Markdown with a
    small toolbar, an internal-link picker (stable references, never raw URLs) and a live, sanitized preview. The analysis
    is a plain Good / Needs attention checklist — there is no numeric score. Escaped Blade output only, except the
    preview pane, which is filled from the server's sanitized Markdown renderer.

    The right rail sits OUTSIDE <form id="article-form">, so every rail field that must be saved or posted with the article
    (featured image, overlap acknowledgement, schedule time) and every rail button that posts the article uses form="article-form".

    Expects: $workspaceUid, $businessUid, $business, $article (?), $hasPendingDraft, $pages, $assets, $featuredUid,
    $suggestions, $analysis (?), $intents, $timezone, $scheduledLocal, $permalinkBase, $savedAt (?).
--}}
@extends('layouts/contentLayoutMaster')

@section('title', $article ? 'Edit article' : 'New article')

@section('page-style')
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
@endsection

@php
    $route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$workspaceUid, $businessUid], $extra));
    $v = fn (string $key, $default = '') => old($key, $article?->{$key} ?? $default);
    $status = $article?->status?->value ?? 'new';
    $published = $status === 'published';
    $formAction = $article ? $route('articles.update', [$article->uid]) : $route('articles.store');
    $supportsDefault = old('supports_page_uid', $article?->supports_page_uid ?? request('page'));
    $featured = old('featured_asset_uid', $featuredUid);
    $strong = $analysis['overlap']['strong'] ?? [];
    $checks = $analysis['checks'] ?? [];
    $goodCount = count(array_filter($checks, fn ($c) => $c['status'] === 'good'));
    $imageCheck = collect($checks)->firstWhere('key', 'image');
    $overlapArticle = collect($strong)->firstWhere('type', 'article');
    $overlapTitle = count($strong) > 0 && collect($strong)->every(fn ($f) => $f['type'] === 'article') ? 'Overlaps an existing article'
        : (count($strong) > 0 && collect($strong)->every(fn ($f) => $f['type'] === 'page') ? 'Overlaps one of your pages' : 'Overlaps existing content');
    $slugValue = (string) $v('slug');
    $host = rtrim($permalinkBase, '/');

    // The save status is driven by what actually happened: a stored timestamp from a successful save, or a refused save.
    $refused = $errors->any() || session('status') === 'error';
    $savedAgo = $savedAt === null ? null : ($savedAt->gt(now()->subMinute()) ? 'just now' : $savedAt->diffForHumans());
    $statusLabels = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived'];
    if ($refused) {
        $pillState = 'error';
        $pillText = 'Not saved';
    } elseif (! $article) {
        $pillState = 'new';
        $pillText = 'New draft · not saved yet';
    } else {
        $pillState = $status;
        $pillText = ($statusLabels[$status] ?? ucfirst($status))
            . ($published ? ($hasPendingDraft && $savedAgo ? ' · draft changes saved ' . $savedAgo : '') : ($savedAgo ? ' · saved ' . $savedAgo : ''));
    }
    $chevron = '<svg class="ae-ic ae-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5"></path></svg>';
@endphp

@section('content')
    <style>
        .ae { --ae-brand:#B4514A; --ae-brand-d:#8F3B35; --ae-ink:#1F1A19; --ae-ink2:#2E2826; --ae-mute:#6B625E; --ae-line:#E9E4DF; --ae-line2:#F0EBE6; --ae-field:#D6CFC8;
              font-family:'Figtree',system-ui,-apple-system,'Segoe UI',sans-serif; color:var(--ae-ink); container-type:inline-size; container-name:ae; }
        .ae *, .ae *::before, .ae *::after { box-sizing:border-box; }
        .ae a { text-decoration:none; }
        .ae .ae-ic { width:16px; height:16px; flex:none; stroke:currentColor; fill:none; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
        .ae .ae-mut { font-size:12.5px; color:var(--ae-mute); }
        .ae .ae-sr { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
        .ae :focus-visible { outline:2px solid var(--ae-brand); outline-offset:2px; }

        .ae-page { display:flex; flex-direction:column; gap:16px; }
        .ae-top { display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
        .ae-back { display:inline-flex; align-items:center; gap:6px; min-height:36px; color:var(--ae-brand-d); font-size:13.5px; font-weight:600; }
        .ae-back:hover { color:var(--ae-brand-d); text-decoration:underline; }
        .ae-spacer { flex:1; }
        .ae-pill { display:inline-flex; align-items:center; height:24px; padding:0 9px; border-radius:999px; font-size:12px; font-weight:600; background:#FDF1DC; color:#7A4A05; }
        .ae-pill[data-state="scheduled"] { background:#E4EEF9; color:#1F4E8C; }
        .ae-pill[data-state="published"] { background:#E3F3EA; color:#17693F; }
        .ae-pill[data-state="archived"] { background:#EDE8E3; color:#4A4340; }
        .ae-pill[data-state="error"], .ae-pill[data-state="unsaved"] { background:#FBE9E7; color:#A3352A; }
        .ae-save { display:inline-flex; align-items:center; justify-content:center; min-height:36px; padding:0 14px; border-radius:10px; border:1px solid var(--ae-field); background:#fff; color:var(--ae-ink2); font:inherit; font-size:13.5px; font-weight:600; cursor:pointer; }
        .ae-save:hover { background:#FBF9F7; }

        .ae-notice { display:flex; flex-wrap:wrap; align-items:center; gap:12px; padding:14px 18px; border-radius:14px; background:linear-gradient(120deg,#fff 55%,#F3EEF9); border:1px solid var(--ae-line); }
        .ae-notice .ae-tile { width:36px; height:36px; border-radius:10px; background:#EFE8F7; color:#5B3A8E; display:flex; align-items:center; justify-content:center; flex:none; }
        .ae-notice .ae-body { flex:1 1 400px; display:flex; flex-direction:column; gap:2px; min-width:0; }
        .ae-notice b { font-size:14.5px; }
        .ae-note { padding:12px 16px; border-radius:12px; border:1px solid var(--ae-line); background:#fff; font-size:13.5px; color:var(--ae-ink2); }
        .ae-note.is-error { background:#FBE9E7; border-color:#F0C4BE; color:#7A2018; }
        .ae-note p { margin:0; }

        .ae-cols { display:flex; gap:20px; align-items:flex-start; }
        .ae-main { flex:1; min-width:0; display:flex; flex-direction:column; gap:16px; }
        .ae-rail { width:350px; flex:none; display:flex; flex-direction:column; gap:16px; }
        .ae-card { background:#fff; border:1px solid var(--ae-line); border-radius:16px; }

        .ae-editor { padding:28px 36px 32px; display:flex; flex-direction:column; gap:14px; }
        .ae textarea.ae-title { border:0; outline:0; resize:none; font:inherit; font-size:34px; font-weight:800; letter-spacing:-.02em; line-height:1.15; color:var(--ae-ink); padding:0; background:transparent; width:100%; overflow:hidden; min-height:40px; }
        .ae textarea.ae-title::placeholder, .ae textarea.ae-excerpt::placeholder { color:#C9C0BA; }
        .ae .ae-title:focus-visible, .ae .ae-excerpt:focus-visible, .ae .ae-bodyfield:focus-visible { outline:0; }
        .ae-permalink { display:flex; flex-wrap:wrap; align-items:center; gap:6px; font-size:13px; color:var(--ae-mute); min-width:0; overflow-wrap:anywhere; }
        .ae-permalink .ae-slug { color:var(--ae-ink); font-weight:500; }
        .ae-permalink .ae-slug.is-empty { color:var(--ae-mute); font-weight:400; font-style:italic; }
        .ae-permalink input { height:30px; min-width:12rem; flex:1 1 12rem; padding:0 8px; border:1px solid var(--ae-field); border-radius:8px; font:inherit; font-size:13px; color:var(--ae-ink); }
        .ae-permalink .ae-link-btn { margin-left:4px; border:0; background:none; padding:0; min-height:24px; font:inherit; font-weight:600; color:var(--ae-brand-d); cursor:pointer; }
        .ae-permalink [hidden] { display:none !important; }
        .ae textarea.ae-excerpt { border:0; border-left:3px solid var(--ae-line2); outline:0; resize:none; font:inherit; font-size:16px; line-height:1.5; color:#5E5652; padding:2px 0 2px 14px; background:transparent; width:100%; overflow:hidden; }

        .ae-toolbar { position:sticky; top:var(--ae-sticky-top,0); z-index:5; display:flex; flex-wrap:wrap; align-items:center; gap:2px; padding:6px 8px; border:1px solid var(--ae-line); border-radius:12px; background:#fff; box-shadow:0 2px 8px rgba(31,26,25,.04); }
        .ae .ae-tb { height:34px; min-width:34px; padding:0 9px; border:0; border-radius:8px; background:transparent; font:inherit; font-size:13.5px; font-weight:600; color:#4A4340; display:inline-flex; align-items:center; justify-content:center; gap:6px; cursor:pointer; }
        .ae .ae-tb:hover, .ae .ae-tb[aria-pressed="true"] { background:#F4F0EC; }
        .ae .ae-tb-sep { width:1px; height:20px; background:var(--ae-line); margin:0 4px; }
        .ae-stats { padding-right:6px; }

        .ae-prose, .ae textarea.ae-bodyfield { font-family:Georgia,'Times New Roman',serif; font-size:17px; line-height:1.7; color:var(--ae-ink2); padding:6px 2px; }
        .ae textarea.ae-bodyfield { display:block; width:100%; min-height:20rem; border:0; outline:0; resize:none; background:transparent; overflow:hidden; }
        .ae-prose { overflow-wrap:anywhere; min-height:8rem; }
        .ae-prose p { margin:0 0 14px; }
        .ae-prose h2 { margin:0 0 10px; font-size:22px; font-weight:700; line-height:1.3; }
        .ae-prose h3 { margin:0 0 10px; font-size:18px; font-weight:700; line-height:1.3; }
        .ae-prose ul, .ae-prose ol { margin:0 0 14px; padding-left:22px; }
        .ae-prose a { color:var(--ae-brand-d); text-decoration:underline; }
        .ae-prose > :last-child { margin-bottom:0; }
        .ae-prose[hidden], .ae textarea.ae-bodyfield[hidden] { display:none; }

        .ae-google { padding:18px 22px; display:flex; flex-direction:column; gap:14px; }
        .ae-google h2 { margin:0; font-size:16px; font-weight:700; line-height:1.3; }
        .ae-google .ae-sub { display:flex; flex-direction:column; gap:2px; }
        .ae-row { display:flex; flex-wrap:wrap; gap:18px; }
        .ae-fields { flex:1 1 360px; display:flex; flex-direction:column; gap:12px; min-width:0; }
        .ae-serp-wrap { flex:1 1 320px; min-width:0; }
        .ae-fld { display:flex; flex-direction:column; gap:6px; min-width:0; }
        .ae-fld > label { font-size:13px; font-weight:600; color:var(--ae-ink2); }
        .ae-fld > label .ae-mut { font-weight:400; }
        .ae .ae-ip { height:42px; padding:0 12px; border:1px solid var(--ae-field); border-radius:10px; background:#fff; font:inherit; font-size:14px; color:var(--ae-ink); width:100%; }
        .ae textarea.ae-ip { height:72px; padding:10px 12px; resize:none; line-height:1.45; }
        .ae select.ae-ip { appearance:none; -webkit-appearance:none; padding-right:34px; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238A817C' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M7 10l5 5 5-5'/%3E%3C/svg%3E") no-repeat right 10px center / 14px; text-overflow:ellipsis; }
        .ae .ae-ip:focus-visible { outline:2px solid var(--ae-brand); outline-offset:0; border-color:var(--ae-brand); }
        .ae-serp { padding:14px; border-radius:12px; background:#FBF9F7; border:1px solid var(--ae-line2); display:flex; flex-direction:column; gap:3px; overflow-wrap:anywhere; }
        .ae-serp .u { font-size:12px; color:#4A4340; }
        .ae-serp .t { font-size:17px; color:#1A0DAB; line-height:1.25; }
        .ae-serp .d { font-size:13px; line-height:1.45; color:#4D5156; }
        .ae-meta { display:flex; flex-wrap:wrap; gap:12px; padding-top:12px; border-top:1px solid var(--ae-line2); }
        .ae-meta .ae-fld:nth-child(1) { flex:1 1 220px; } .ae-meta .ae-fld:nth-child(2) { flex:0 1 160px; min-width:140px; } .ae-meta .ae-fld:nth-child(3) { flex:1 1 200px; }
        .ae details.ae-more summary { list-style:none; display:inline-flex; align-items:center; gap:6px; min-height:44px; cursor:pointer; font-size:13.5px; font-weight:500; color:#8A817C; }
        .ae details.ae-more summary::-webkit-details-marker { display:none; }
        .ae details.ae-more summary:hover { color:#5E5652; }
        .ae details.ae-more[open] summary .ae-chev { transform:rotate(180deg); }
        .ae .ae-chev { width:14px; height:14px; }
        .ae-more-body { display:flex; flex-wrap:wrap; gap:12px; padding-bottom:4px; }
        .ae-more-body .ae-fld { flex:1 1 240px; }
        .ae-check { display:flex; align-items:flex-start; gap:8px; flex:1 1 100%; font-size:13.5px; }
        .ae-check input { margin-top:3px; }

        .ae-side { padding:18px; display:flex; flex-direction:column; gap:12px; }
        .ae-side h2 { margin:0; font-size:15px; font-weight:700; }
        .ae .ae-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:44px; padding:0 16px; border-radius:10px; border:0; font:inherit; font-size:14px; font-weight:600; white-space:nowrap; cursor:pointer; }
        .ae .ae-btn.is-primary { background:var(--ae-brand); color:#fff; width:100%; }
        .ae .ae-btn.is-primary:hover { background:var(--ae-brand-d); color:#fff; }
        .ae .ae-btn.is-primary[disabled], .ae .ae-btn.is-primary[aria-disabled="true"] { opacity:.55; cursor:not-allowed; background:var(--ae-brand); }
        .ae .ae-btn.is-ghost { border:1px solid var(--ae-field); background:#fff; color:var(--ae-ink2); }
        .ae .ae-btn.is-ghost:hover { background:#FBF9F7; color:var(--ae-ink2); }
        .ae .ae-btn.is-grow { flex:1; }
        .ae .ae-btn.is-full { width:100%; }
        .ae-btnrow { display:flex; gap:8px; }
        .ae-center { text-align:center; }
        .ae .ae-textbtn { align-self:center; border:0; background:none; padding:0; min-height:36px; font:inherit; font-size:13px; font-weight:600; color:var(--ae-mute); cursor:pointer; }
        .ae .ae-textbtn:hover { color:var(--ae-brand-d); }
        .ae .ae-textbtn.is-danger:hover { color:#A3352A; }
        .ae-schedule { display:flex; flex-direction:column; gap:8px; padding:12px; border:1px solid var(--ae-line); border-radius:12px; background:#FBF9F7; }
        .ae-schedule[hidden] { display:none; }
        .ae-warn { display:flex; gap:10px; padding:12px; border-radius:12px; background:#FDF6E3; border:1px solid #EBD6A8; color:#3D2A05; }
        .ae-warn > svg { color:#7A4A05; margin-top:1px; }
        .ae-warn .ae-wb { flex:1; min-width:0; display:flex; flex-direction:column; gap:6px; font-size:13px; }
        .ae-warn b { font-size:13.5px; }
        .ae-warn ul { margin:0; padding-left:18px; }
        .ae-warn .ae-actions { display:flex; gap:10px; flex-wrap:wrap; }
        .ae-warn a, .ae-warn button.ae-textbtn { font-weight:600; color:var(--ae-brand-d); min-height:24px; align-self:auto; font-size:13px; }
        .ae-warn label { display:flex; align-items:center; gap:8px; margin-top:2px; font-size:13px; }
        .ae-blockers { padding:12px; border-radius:12px; background:#FBF9F7; border:1px solid var(--ae-line); font-size:13px; }
        .ae-blockers ul { margin:4px 0 0; padding-left:18px; }

        .ae-check-card { padding:18px; }
        .ae-check-head { display:flex; align-items:center; margin-bottom:4px; }
        .ae-check-head b { flex:1; font-size:15px; }
        .ae-bar { height:6px; border-radius:999px; background:#EEE9E4; overflow:hidden; margin:8px 0 6px; display:flex; gap:2px; }
        .ae-bar span:first-child { background:#1F8A55; }
        .ae-ci { display:flex; align-items:flex-start; gap:10px; padding:9px 0; border-top:1px solid var(--ae-line2); font-size:13.5px; }
        .ae-ci .d { width:20px; height:20px; border-radius:50%; flex:none; display:flex; align-items:center; justify-content:center; margin-top:1px; background:#E3F3EA; color:#17693F; }
        .ae-ci[data-status="attention"] .d { background:#FDF1DC; color:#7A4A05; }
        .ae-ci .d svg { width:11px; height:11px; stroke-width:3; }
        .ae-ci .d b { font-size:11px; }
        .ae-ci > div { flex:1; display:flex; flex-direction:column; gap:1px; min-width:0; }

        .ae-img-head { display:flex; align-items:center; gap:8px; }
        .ae-img-head h2 { flex:1; }
        .ae-need { display:inline-flex; align-items:center; height:22px; padding:0 8px; border-radius:999px; background:#FDF1DC; color:#7A4A05; font-size:11.5px; font-weight:700; }
        .ae-assets { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
        .ae-asset { position:relative; display:block; cursor:pointer; }
        .ae-asset input { position:absolute; opacity:0; inset:0; margin:0; cursor:pointer; }
        .ae-asset img { display:block; width:100%; aspect-ratio:1; object-fit:cover; border-radius:8px; background:#EEE9E4; }
        .ae-asset input:checked + img { box-shadow:0 0 0 3px var(--ae-brand); }
        .ae-asset input:focus-visible + img { outline:3px solid var(--ae-brand); outline-offset:3px; }
        .ae-assets:not(.is-all) .ae-asset.is-extra { display:none; }
        .ae-none { position:absolute; opacity:0; pointer-events:none; }
        .ae-linkbtn { align-self:flex-start; border:0; background:none; padding:0; min-height:28px; font:inherit; font-size:13px; font-weight:600; color:var(--ae-brand-d); cursor:pointer; }
        .ae-linkbtn:hover { text-decoration:underline; }
        .ae-imgacts { display:flex; flex-wrap:wrap; gap:4px 16px; }

        .ae-lc { display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px solid var(--ae-line); border-radius:10px; background:#fff; font:inherit; font-size:13.5px; color:var(--ae-ink); text-align:left; cursor:pointer; width:100%; }
        .ae-lc:hover { border-color:var(--ae-field); background:#FBF9F7; }
        .ae-lc .ae-ic { width:14px; height:14px; color:var(--ae-brand-d); }
        .ae-lc span:nth-child(2) { flex:1; min-width:0; overflow-wrap:anywhere; }
        .ae-secondary { display:flex; flex-direction:column; gap:8px; padding-top:4px; border-top:1px solid var(--ae-line2); }
        .ae-secondary form { margin:0; }

        /* Narrow containers (the app sidebar takes part of the viewport, so this is the editor's own width): stack. */
        @container ae (max-width: 960px) {
            .ae-cols { flex-direction:column; align-items:stretch; }
            .ae-rail { width:100%; }
        }
        @container ae (max-width: 640px) {
            .ae-editor { padding:20px 18px 22px; }
            .ae textarea.ae-title { font-size:26px; }
            .ae-google { padding:16px; }
            .ae-notice { padding:12px 14px; }
        }
        @media (prefers-reduced-motion: no-preference) { .ae .ae-chev { transition:transform .15s; } }
    </style>

    <div class="ae"><div class="ae-page">

        <div class="ae-top" data-role="editor-top">
            <a class="ae-back" href="{{ $route('articles.index') }}"><svg class="ae-ic" viewBox="0 0 24 24" style="width:14px;height:14px" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"></path></svg>Articles</a>
            <span class="ae-spacer"></span>
            <span class="ae-pill" data-role="save-status" data-state="{{ $pillState }}" data-saved-text="{{ $pillText }}" data-saved-state="{{ $pillState }}" role="status" aria-live="polite">{{ $pillText }}</span>
            @can('manage_seo')
                <button class="ae-save" type="submit" form="article-form" data-role="save-draft">{{ $published ? 'Save draft changes' : 'Save draft' }}</button>
            @endcan
        </div>

        @if($article?->ai_generated && $status === 'draft')
            <div class="ae-notice" data-role="ai-notice">
                <span class="ae-tile"><svg class="ae-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"></path></svg></span>
                <div class="ae-body"><b>Written by AI from your services and prices</b><span class="ae-mut">Read it all and fix anything that isn't true for your business. Nothing is published until you say so.</span></div>
            </div>
        @endif

        <x-flash-alert />

        @if($errors->any())
            <div class="ae-note is-error" role="alert" data-role="editor-errors"><p>{{ $errors->first() }}</p></div>
        @endif

        @if($published)
            <div class="ae-note" data-role="published-notice">
                <p>This article is live. Saving here only saves a <strong>draft of your changes</strong>; your live article stays exactly as it is until you press <strong>Publish update</strong>.
                @if($hasPendingDraft) <strong>You have unpublished draft changes.</strong> @endif</p>
            </div>
        @endif

        <div class="ae-cols">
            <div class="ae-main">
                <form id="article-form" method="POST" action="{{ $formAction }}" data-role="article-form" class="ae-main">
                    @csrf

                    <section class="ae-card ae-editor">
                        <label class="ae-sr" for="f-title">Title</label>
                        <textarea id="f-title" class="ae-title" name="title" rows="1" maxlength="180" required placeholder="Give the article a title" data-role="title">{{ $v('title') }}</textarea>

                        <div class="ae-permalink" data-role="permalink">
                            <svg class="ae-ic" viewBox="0 0 24 24" style="width:13px;height:13px" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"></path></svg>
                            <span>{{ $host }}/</span><span class="ae-slug {{ $slugValue === '' ? 'is-empty' : '' }}" id="ae-slug-text">{{ $slugValue !== '' ? $slugValue : 'made from the title' }}</span>
                            <label class="ae-sr" for="f-slug">Web address</label>
                            <input id="f-slug" type="text" name="slug" value="{{ $slugValue }}" maxlength="80" placeholder="made from the title" hidden>
                            <button type="button" class="ae-link-btn" id="ae-slug-edit" aria-controls="f-slug" aria-expanded="false" data-role="slug-edit">Edit</button>
                            <span class="ae-mut ae-sr" id="ae-slug-help">Lowercase letters, numbers and hyphens. @if($article?->hasBeenPublished())If you change a published article's address, the old address keeps redirecting to the new one.@endif</span>
                        </div>

                        <label class="ae-sr" for="f-excerpt">Excerpt</label>
                        <textarea id="f-excerpt" class="ae-excerpt" name="excerpt" rows="1" maxlength="400" placeholder="A short summary, shown on the blog and under the title">{{ $v('excerpt') }}</textarea>

                        <div class="ae-toolbar" role="toolbar" aria-label="Formatting" data-role="md-toolbar">
                            <button type="button" class="ae-tb" data-md="h2" title="Heading" aria-label="Heading">H2</button>
                            <button type="button" class="ae-tb" data-md="h3" title="Subheading" aria-label="Subheading">H3</button>
                            <span class="ae-tb-sep" aria-hidden="true"></span>
                            <button type="button" class="ae-tb" data-md="bold" title="Bold" aria-label="Bold" style="font-weight:800">B</button>
                            <button type="button" class="ae-tb" data-md="italic" title="Italic" aria-label="Italic" style="font-style:italic;font-family:Georgia,serif">I</button>
                            <button type="button" class="ae-tb" data-md="ul" title="List" aria-label="List"><svg class="ae-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"></path></svg></button>
                            <button type="button" class="ae-tb" data-md="link" title="Web link" aria-label="Web link"><svg class="ae-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"></path></svg></button>
                            <span class="ae-tb-sep" aria-hidden="true"></span>
                            <button type="button" class="ae-tb" data-md="preview" title="Show the text as readers will see it" aria-pressed="false" aria-controls="ae-prose" data-role="text-preview-toggle"><svg class="ae-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"></path><circle cx="12" cy="12" r="2.8"></circle></svg><span>Preview text</span></button>
                            <span class="ae-spacer"></span>
                            <span class="ae-mut ae-stats" data-role="word-stats"><span id="c-words">0</span> words · <span id="c-read">1</span> min read</span>
                        </div>

                        <label class="ae-sr" for="f-body">Article text</label>
                        <textarea id="f-body" class="ae-bodyfield" name="body" data-role="article-body" placeholder="Write the article. Start sections with a Heading — the title is already the page heading.">{{ $v('body') }}</textarea>
                        <div id="ae-prose" class="ae-prose" data-role="md-preview" aria-live="polite" hidden></div>
                    </section>

                    <section class="ae-card ae-google" data-role="search-appearance">
                        <div class="ae-sub"><h2>How it looks on Google</h2><span class="ae-mut">Edit the title and description searchers see.</span></div>
                        <div class="ae-row">
                            <div class="ae-fields">
                                <div class="ae-fld">
                                    <label for="f-seo-title">Search title <span class="ae-mut">· <span id="c-seo">0</span> / 60</span></label>
                                    <input id="f-seo-title" class="ae-ip" type="text" name="seo_title" value="{{ $v('seo_title') }}" maxlength="180" placeholder="Defaults to the title">
                                </div>
                                <div class="ae-fld">
                                    <label for="f-meta">Description <span class="ae-mut">· <span id="c-meta">0</span> / 160</span></label>
                                    <textarea id="f-meta" class="ae-ip" name="meta_description" rows="3" maxlength="320">{{ $v('meta_description') }}</textarea>
                                </div>
                            </div>
                            <div class="ae-serp-wrap">
                                <div class="ae-serp" data-role="serp" aria-label="Search result preview">
                                    <span class="u" id="ae-serp-url"></span>
                                    <span class="t" id="ae-serp-title"></span>
                                    <span class="d" id="ae-serp-desc"></span>
                                </div>
                            </div>
                        </div>
                        <div class="ae-meta">
                            <div class="ae-fld">
                                <label for="f-topic">Target topic</label>
                                <input id="f-topic" class="ae-ip" type="text" name="primary_topic" value="{{ $v('primary_topic') }}" maxlength="190" placeholder="e.g. how much space does a photo booth need">
                            </div>
                            <div class="ae-fld">
                                <label for="f-intent">Search intent</label>
                                <select id="f-intent" class="ae-ip" name="search_intent">
                                    <option value="">Not set</option>
                                    @foreach($intents as $intent)
                                        <option value="{{ $intent->value }}" @selected($v('search_intent') === $intent->value)>{{ $intent->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="ae-fld">
                                <label for="f-supports">Supports page</label>
                                <select id="f-supports" class="ae-ip" name="supports_page_uid">
                                    <option value="">None</option>
                                    @foreach($pages as $page)
                                        @if($page['linkable'] && ! in_array($page['kind'], ['other'], true))
                                            <option value="{{ $page['uid'] }}" @selected($supportsDefault === $page['uid'])>{{ $page['title'] }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <details class="ae-more" data-role="author-visibility" @if((bool) $v('noindex', false) || $errors->has('author_name')) open @endif>
                            <summary>Author &amp; visibility {!! $chevron !!}</summary>
                            <div class="ae-more-body">
                                <div class="ae-fld">
                                    <label for="f-author">Author name</label>
                                    <input id="f-author" class="ae-ip" type="text" name="author_name" value="{{ $v('author_name') }}" maxlength="120" placeholder="{{ $business->name }}">
                                    <span class="ae-mut">Shown as the byline. Leave blank to use your business name.</span>
                                </div>
                                <div class="ae-check">
                                    <input type="hidden" name="noindex" value="0">
                                    <input id="f-noindex" type="checkbox" name="noindex" value="1" @checked((bool) $v('noindex', false))>
                                    <label for="f-noindex">Hide this article from search engines<br><span class="ae-mut">It stays on your website for visitors who have the link. It also stays out of your sitemap.</span></label>
                                </div>
                            </div>
                        </details>
                    </section>
                </form>
            </div>

            <aside class="ae-rail" aria-label="Publishing and tools">
                <section class="ae-card ae-side" data-role="publish-panel">
                    <h2 class="ae-sr">Publish</h2>

                    @if($article)
                        @if(count($strong) > 0)
                            <div class="ae-warn" data-role="overlap-warning">
                                <svg class="ae-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                                <div class="ae-wb">
                                    <b>{{ $overlapTitle }}</b>
                                    @if(count($strong) === 1)
                                        <span>{{ $strong[0]['reason'] }}</span>
                                    @else
                                        <ul>@foreach($strong as $finding)<li>{{ $finding['reason'] }}</li>@endforeach</ul>
                                    @endif
                                    <div class="ae-actions">
                                        @if($overlapArticle)<a href="{{ $route('articles.edit', [$overlapArticle['uid']]) }}" target="_blank" rel="noopener" data-role="open-overlap">Open that article</a>@endif
                                        <button type="button" class="ae-textbtn" data-focus="#f-topic" data-role="change-topic">Change topic</button>
                                    </div>
                                    <label for="f-ack"><input id="f-ack" type="checkbox" name="acknowledge_overlap" value="1" form="article-form"> Publish anyway</label>
                                </div>
                            </div>
                        @endif

                        @if(count($analysis['blockers'] ?? []) > 0)
                            <div class="ae-blockers" data-role="blockers">
                                <strong>Before you can publish</strong>
                                <ul>@foreach($analysis['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
                            </div>
                        @endif

                        @can('manage_seo')
                            @if(in_array($status, ['draft', 'scheduled'], true))
                                <button class="ae-btn is-primary" type="submit" form="article-form" formaction="{{ $route('articles.publish', [$article->uid]) }}" data-role="publish" @if(count($strong) > 0) data-requires-ack="#f-ack" @endif>Publish now</button>
                            @elseif($published)
                                <button class="ae-btn is-primary" type="submit" form="article-form" formaction="{{ $route('articles.publish', [$article->uid]) }}" data-role="publish-update" @if(count($strong) > 0) data-requires-ack="#f-ack" @endif>Publish update</button>
                            @endif
                        @endcan

                        <div class="ae-btnrow">
                            <a class="ae-btn is-ghost" href="{{ $route('articles.preview', [$article->uid]) }}" target="_blank" rel="noopener" data-role="preview">Preview</a>
                            @can('manage_seo')
                                @if(in_array($status, ['draft', 'scheduled'], true))
                                    <button class="ae-btn is-ghost is-grow" type="button" data-role="schedule-toggle" aria-expanded="{{ $status === 'scheduled' ? 'true' : 'false' }}" aria-controls="ae-schedule"><svg class="ae-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M4 10h16M8 3v4M16 3v4"></path></svg>{{ $status === 'scheduled' ? 'Rescheduling…' : 'Schedule…' }}</button>
                                @endif
                            @endcan
                        </div>

                        @can('manage_seo')
                            @if(in_array($status, ['draft', 'scheduled'], true))
                                <div class="ae-schedule" id="ae-schedule" @if($status !== 'scheduled') hidden @endif>
                                    <label for="f-schedule" class="ae-mut" style="font-weight:600">Publish on ({{ $timezone }})</label>
                                    <input id="f-schedule" class="ae-ip" type="datetime-local" name="scheduled_at_local" form="article-form" value="{{ $scheduledLocal }}">
                                    <button class="ae-btn is-ghost is-full" type="submit" form="article-form" formaction="{{ $route('articles.schedule', [$article->uid]) }}" data-role="schedule" @if(count($strong) > 0) data-requires-ack="#f-ack" @endif>{{ $status === 'scheduled' ? 'Reschedule' : 'Schedule' }}</button>
                                    @if($status === 'scheduled')
                                        <form method="POST" action="{{ $route('articles.draft', [$article->uid]) }}">@csrf
                                            <button class="ae-btn is-ghost is-full" type="submit" data-role="unschedule">Cancel schedule (back to draft)</button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                            <span class="ae-mut ae-center">Times in {{ $timezone }}</span>

                            @if($published)
                                <p class="ae-mut ae-center" style="margin:0">Published {{ $article->published_at?->copy()->setTimezone($timezone)->format('M j, Y g:i A') }}. The live article changes only when you publish an update.</p>
                                <div class="ae-secondary">
                                    @if($hasPendingDraft)
                                        <form method="POST" action="{{ $route('articles.discard', [$article->uid]) }}">@csrf
                                            <button class="ae-btn is-ghost is-full" type="submit" data-role="discard-draft">Discard draft changes</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ $route('articles.reviewed', [$article->uid]) }}">@csrf
                                        <button class="ae-btn is-ghost is-full" type="submit" data-role="mark-reviewed">Mark as reviewed</button>
                                    </form>
                                    <form method="POST" action="{{ $route('articles.track', [$article->uid]) }}">@csrf
                                        <button class="ae-btn is-ghost is-full" type="submit" data-role="track-topic">Track this topic</button>
                                        <span class="ae-mut" style="display:block;margin-top:4px">Adds the topic to your keywords. Rank tracking stays off until you turn it on.</span>
                                    </form>
                                </div>
                            @endif

                            @if($status === 'archived')
                                <form method="POST" action="{{ $route('articles.restore', [$article->uid]) }}" style="margin:0">@csrf
                                    <button class="ae-btn is-ghost is-full" type="submit" data-role="restore">Restore as draft</button>
                                </form>
                            @else
                                <form method="POST" action="{{ $route('articles.archive', [$article->uid]) }}" style="display:flex;justify-content:center;margin:0" onsubmit="return confirm('Archive this article? It leaves your website, the blog and the sitemap. Its address stays reserved.');">@csrf
                                    <button class="ae-textbtn is-danger" type="submit" data-role="archive">Archive article</button>
                                </form>
                            @endif
                        @endcan
                    @else
                        <p class="ae-mut" style="margin:0">Save the draft to preview it, run the checklist, schedule it or publish it. A draft is private until you publish it.</p>
                    @endif
                </section>

                @if($article)
                    <section class="ae-card ae-check-card" data-role="analysis">
                        <div class="ae-check-head"><b>Checklist</b><span class="ae-mut" data-role="analysis-score">{{ $goodCount }} of {{ count($checks) }}</span></div>
                        <div class="ae-bar" role="img" aria-label="{{ $goodCount }} of {{ count($checks) }} checks are good"><span style="flex:{{ $goodCount }}"></span><span style="flex:{{ count($checks) - $goodCount }}"></span></div>
                        <p class="ae-sr" data-role="analysis-overall">{{ ($analysis['overall'] ?? 'attention') === 'good' ? 'Everything looks good.' : 'A few things need attention.' }}</p>
                        @foreach($checks as $check)
                            <div class="ae-ci" data-check="{{ $check['key'] }}" data-status="{{ $check['status'] }}">
                                <span class="d" aria-hidden="true">@if($check['status'] === 'good')<svg class="ae-ic" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>@else<b>!</b>@endif</span>
                                <div>
                                    <span><span class="ae-sr">{{ $check['status'] === 'good' ? 'Good: ' : 'Needs attention: ' }}</span>{{ $check['label'] }}</span>
                                    <span class="ae-mut">{{ $check['detail'] }}</span>
                                </div>
                            </div>
                        @endforeach
                        <span class="ae-mut" style="display:block;margin-top:8px">Reflects your last save. Save, then re-open this page to refresh it.</span>
                    </section>
                @endif

                <section class="ae-card ae-side" data-role="featured-image">
                    <div class="ae-img-head">
                        <h2>Featured image</h2>
                        @if($article && ($imageCheck['status'] ?? 'good') === 'attention')<span class="ae-need" data-role="image-needed">Needed</span>@endif
                    </div>
                    @if(count($assets) === 0)
                        <p class="ae-mut" style="margin:0" data-role="no-assets">Your website's image library is empty. Add photos from your website's media screen first.</p>
                    @else
                        <div class="ae-assets" role="radiogroup" aria-label="Featured image" data-role="asset-grid" id="ae-assets">
                            <input class="ae-none" type="radio" name="featured_asset_uid" value="" form="article-form" id="ae-no-image" @checked(! $featured) aria-label="No featured image">
                            @foreach($assets as $i => $asset)
                                <label class="ae-asset {{ $i >= 6 && $featured !== $asset['uid'] ? 'is-extra' : '' }}" title="{{ $asset['alt'] !== '' ? $asset['alt'] : 'No alt text yet' }}">
                                    <input type="radio" name="featured_asset_uid" value="{{ $asset['uid'] }}" form="article-form" @checked($featured === $asset['uid'])>
                                    <img src="{{ $asset['thumb'] }}" alt="{{ $asset['alt'] !== '' ? $asset['alt'] : 'Photo ' . ($i + 1) }}" loading="lazy" width="92" height="92">
                                </label>
                            @endforeach
                        </div>
                        <div class="ae-imgacts">
                            @if(count($assets) > 6)<button type="button" class="ae-linkbtn" id="ae-assets-more" aria-expanded="false" aria-controls="ae-assets" data-total="{{ count($assets) }}">See all {{ count($assets) }} photos</button>@endif
                            <button type="button" class="ae-linkbtn" id="ae-assets-clear" @if(! $featured) hidden @endif>Remove image</button>
                        </div>
                    @endif
                </section>

                <section class="ae-card ae-side" data-role="link-suggestions">
                    <h2>Link to your pages</h2>
                    <span class="ae-mut" style="margin-top:-6px">Inserts where your cursor is.</span>
                    @forelse($suggestions as $s)
                        <button type="button" class="ae-lc" data-insert="[{{ str_replace(['[', ']'], '', $s['anchor']) }}]({{ $s['type'] }}:{{ $s['uid'] }})">
                            <svg class="ae-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"></path></svg>
                            <span>{{ $s['title'] }}</span><span class="ae-mut">{{ $s['type'] === 'article' ? 'Article' : 'Page' }}</span>
                        </button>
                    @empty
                        <p class="ae-mut" style="margin:0">Choose the page this article supports and suggestions appear here.</p>
                    @endforelse
                </section>
            </aside>
        </div>
    </div></div>

    <script>
        (function () {
            var form = document.getElementById('article-form');
            var body = document.getElementById('f-body');
            var prose = document.getElementById('ae-prose');
            var title = document.getElementById('f-title');
            var excerpt = document.getElementById('f-excerpt');
            var slug = document.getElementById('f-slug');
            var seoTitle = document.getElementById('f-seo-title');
            var meta = document.getElementById('f-meta');
            var pill = document.querySelector('[data-role="save-status"]');
            var words = document.getElementById('c-words');
            var read = document.getElementById('c-read');
            var token = form.querySelector('input[name="_token"]').value;
            var previewUrl = @json($route('markdown-preview'));
            var host = @json($host);
            var timer = null, dirty = false, previewing = false;

            function grow(el) { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }
            function growAll() { [title, excerpt, body].forEach(function (el) { if (el.offsetParent !== null) { grow(el); } }); }

            title.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
            title.addEventListener('input', function () { title.value = title.value.replace(/[\r\n]+/g, ' '); });

            function markDirty() {
                if (dirty) { return; }
                dirty = true;
                pill.setAttribute('data-state', 'unsaved');
                pill.textContent = 'Unsaved changes';
            }
            form.addEventListener('input', function () { markDirty(); });
            form.addEventListener('change', function () { markDirty(); });
            document.querySelectorAll('[form="article-form"]').forEach(function (el) {
                el.addEventListener('input', markDirty); el.addEventListener('change', markDirty);
            });
            form.addEventListener('submit', function () { dirty = false; });
            document.querySelectorAll('button[form="article-form"][type="submit"]').forEach(function (b) { b.addEventListener('click', function () { dirty = false; }); });
            window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

            // Cmd/Ctrl+S saves the draft.
            document.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                    var save = document.querySelector('[data-role="save-draft"]');
                    if (save) { e.preventDefault(); save.click(); }
                }
            });

            // Search preview + counters.
            function text(id, value) { document.getElementById(id).textContent = value; }
            function serp() {
                var t = (seoTitle.value.trim() || title.value.trim() || 'Untitled article');
                var d = (meta.value.trim() || excerpt.value.trim());
                var s = slug.value.trim();
                var segs = host.split('/').filter(Boolean);
                text('ae-serp-url', segs.join(' › ') + (s ? ' › ' + s : ''));
                text('ae-serp-title', t);
                text('ae-serp-desc', d || 'Add a description so searchers see a useful summary.');
                text('c-seo', seoTitle.value.length || title.value.length);
                text('c-meta', meta.value.length);
                var st = document.getElementById('ae-slug-text');
                st.textContent = s || 'made from the title';
                st.classList.toggle('is-empty', s === '');
            }
            [title, excerpt, slug, seoTitle, meta].forEach(function (el) { el.addEventListener('input', serp); });
            serp();

            // Permalink "Edit".
            var slugBtn = document.getElementById('ae-slug-edit');
            slugBtn.addEventListener('click', function () {
                var open = slug.hasAttribute('hidden');
                if (open) { slug.removeAttribute('hidden'); document.getElementById('ae-slug-text').setAttribute('hidden', ''); slug.focus(); }
                else { slug.setAttribute('hidden', ''); document.getElementById('ae-slug-text').removeAttribute('hidden'); }
                slugBtn.textContent = open ? 'Done' : 'Edit';
                slugBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            @if($errors->has('slug'))
                slugBtn.click();
            @endif

            // Formatting. The caret and selection live on the textarea, so they survive clicks elsewhere on the page.
            function showEditor() {
                if (!previewing) { return; }
                previewing = false;
                prose.hidden = true; body.hidden = false;
                var t = document.querySelector('[data-md="preview"]');
                t.setAttribute('aria-pressed', 'false');
                grow(body);
            }
            function refresh() {
                var t = body.value.trim();
                var n = t === '' ? 0 : t.split(/\s+/).length;
                words.textContent = n;
                read.textContent = Math.max(1, Math.ceil(n / 200));
                grow(body);
                clearTimeout(timer);
                if (!previewing) { return; }
                timer = setTimeout(renderPreview, 250);
            }
            function renderPreview() {
                var data = new FormData();
                data.append('body', body.value);
                fetch(previewUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (j) { if (j && typeof j.html === 'string' && previewing) { prose.innerHTML = j.html; } });
            }
            function wrap(prefix, suffix, placeholder) {
                showEditor();
                var s = body.selectionStart, e = body.selectionEnd, sel = body.value.slice(s, e) || placeholder;
                body.setRangeText(prefix + sel + suffix, s, e, 'end');
                body.focus(); markDirty(); refresh();
            }
            function lineStart(prefix) {
                showEditor();
                var s = body.selectionStart, start = body.value.lastIndexOf('\n', s - 1) + 1;
                body.setRangeText(prefix, start, start, 'end');
                body.focus(); markDirty(); refresh();
            }
            function insert(value) {
                showEditor();
                var s = body.selectionStart;
                body.setRangeText(value, s, body.selectionEnd, 'end');
                body.focus(); markDirty(); refresh();
            }
            document.querySelectorAll('[data-md]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    switch (btn.getAttribute('data-md')) {
                        case 'h2': lineStart('## '); break;
                        case 'h3': lineStart('### '); break;
                        case 'bold': wrap('**', '**', 'bold text'); break;
                        case 'italic': wrap('*', '*', 'italic text'); break;
                        case 'ul': lineStart('- '); break;
                        case 'link': wrap('[', '](https://)', 'link text'); break;
                        case 'preview':
                            if (previewing) { showEditor(); body.focus(); break; }
                            previewing = true; body.hidden = true; prose.hidden = false; btn.setAttribute('aria-pressed', 'true');
                            prose.textContent = 'Loading…'; renderPreview();
                            break;
                    }
                });
            });
            document.querySelectorAll('[data-insert]').forEach(function (btn) {
                btn.addEventListener('click', function () { insert(btn.getAttribute('data-insert')); });
            });
            body.addEventListener('input', refresh);
            [title, excerpt].forEach(function (el) { el.addEventListener('input', function () { grow(el); }); });

            // Rail: schedule panel, overlap acknowledgement, topic jump, featured image.
            var toggle = document.querySelector('[data-role="schedule-toggle"]');
            var panel = document.getElementById('ae-schedule');
            if (toggle && panel) {
                toggle.addEventListener('click', function () {
                    var open = panel.hidden;
                    panel.hidden = !open;
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    if (open) { document.getElementById('f-schedule').focus(); }
                });
            }
            var gated = document.querySelectorAll('[data-requires-ack]');
            function syncAck() {
                gated.forEach(function (b) {
                    var box = document.querySelector(b.getAttribute('data-requires-ack'));
                    var ok = !box || box.checked;
                    b.disabled = !ok;
                    b.title = ok ? '' : 'Tick “Publish anyway” to continue';
                });
            }
            gated.forEach(function (b) { var box = document.querySelector(b.getAttribute('data-requires-ack')); if (box) { box.addEventListener('change', syncAck); } });
            syncAck();
            document.querySelectorAll('[data-focus]').forEach(function (b) {
                b.addEventListener('click', function () { var t = document.querySelector(b.getAttribute('data-focus')); if (t) { t.scrollIntoView({ block: 'center' }); t.focus(); } });
            });
            var grid = document.getElementById('ae-assets'), more = document.getElementById('ae-assets-more'), clear = document.getElementById('ae-assets-clear');
            if (more) {
                more.addEventListener('click', function () {
                    var all = !grid.classList.contains('is-all');
                    grid.classList.toggle('is-all', all);
                    more.textContent = all ? 'Show fewer photos' : 'See all ' + more.getAttribute('data-total') + ' photos';
                    more.setAttribute('aria-expanded', all ? 'true' : 'false');
                });
            }
            if (grid && clear) {
                var none = document.getElementById('ae-no-image');
                clear.addEventListener('click', function () { none.checked = true; clear.hidden = true; markDirty(); });
                grid.addEventListener('change', function () { clear.hidden = none.checked; });
            }

            refresh();
            growAll();
            window.addEventListener('resize', growAll);
            if (document.fonts && document.fonts.ready) { document.fonts.ready.then(growAll); }
        })();
    </script>
@endsection
