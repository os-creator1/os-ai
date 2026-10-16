{{--
    The one page heading for a redesigned Settings screen: back link, title (with
    an optional status chip) and a one-line description. The application shell
    (sidebar, top bar) already supplies the product chrome and no breadcrumb, so
    this is the only place the page names itself.

    $backUrl, $title (required); $backLabel, $description, $chip, $chipVariant
    (accent|success|warn|danger, default neutral) optional.
--}}
<a href="{{ $backUrl }}" class="sr-back">
    <x-ds-icon name="arrow-left" size="14" aria-hidden="true" /> {{ $backLabel ?? 'Back to Settings' }}
</a>
<div class="sr-head">
    <div class="sr-head__main">
        <h1 class="sr-title">
            {{ $title }}
            @isset($chip)
                <span class="sr-chip {{ isset($chipVariant) && $chipVariant !== '' ? 'sr-chip--' . $chipVariant : '' }}" style="font-size:.8125rem" data-role="page-chip">{{ $chip }}</span>
            @endisset
        </h1>
        @isset($description)
            <p class="sr-lede">{{ $description }}</p>
        @endisset
    </div>
    @isset($action)
        <div class="sr-head__action">{!! $action !!}</div>
    @endisset
</div>
